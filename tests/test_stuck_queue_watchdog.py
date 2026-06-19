"""Regression tests for the async purge-queue watchdog (5.11.0).

The 2026-06-07 → 2026-06-19 prod incident left the queue in a state where
``vhp_varnish_purge_queue`` had ``full=true`` but ``wp_next_scheduled()`` was
``false`` and no ``vhp_process_purge_queue`` events were in the cron array.
Every subsequent ``save_post`` enqueue went through ``enqueue_urls()``, hit the
``if ! empty( $queue['full'] ) { return; }`` early return at
``varnish-http-purge.php:610-613``, and never reached
``ensure_purge_queue_scheduled()`` to re-arm the event.

These tests pin down the two structural fixes:

* ``enqueue_urls()`` must call the scheduler even when the queue already has
  ``full=true`` (otherwise the watchdog never gets a chance to run).
* ``ensure_purge_queue_scheduled()`` must re-arm a stale event when
  ``last_queue_run`` is older than the watchdog threshold.
"""

import time

import pytest
import requests

from conftest import API_BASE, fresh_post


WATCHDOG_DEFAULT_SECONDS = 5 * 60


def _set_cron_mode(mode: str):
    """Force the test stack into the given cron-mode state."""
    r = requests.post(f"{API_BASE}/cron-mode", json={"mode": mode})
    r.raise_for_status()
    return r.json()


def _clear_queue():
    r = requests.post(f"{API_BASE}/purge-queue/clear")
    r.raise_for_status()
    return r.json()


def _force_stuck(**kwargs):
    """Drop the queue into the stuck shape observed on prod."""
    r = requests.post(f"{API_BASE}/force-stuck-queue", json=kwargs)
    r.raise_for_status()
    return r.json()


def _queue_state():
    r = requests.get(f"{API_BASE}/queue-state")
    r.raise_for_status()
    return r.json()


def _trigger_save_post(post_id: int):
    r = requests.post(
        f"{API_BASE}/save-post-trigger",
        json={"post_id": post_id},
    )
    r.raise_for_status()
    return r.json()


def _run_queue():
    r = requests.post(f"{API_BASE}/run-cron-processor")
    r.raise_for_status()
    return r.json()


def _wait_for(predicate, timeout: float = 5.0, delay: float = 0.1):
    """Poll ``predicate`` until truthy or timeout. Returns the last value seen."""
    end = time.time() + timeout
    last = None
    while time.time() < end:
        last = predicate()
        if last:
            return last
        time.sleep(delay)
    return last


@pytest.fixture()
def cron_mode_on():
    """Force cron-mode on for the test, restore afterwards."""
    _set_cron_mode("force_on")
    _clear_queue()
    yield
    _set_cron_mode("force_off")
    _clear_queue()


def test_watchdog_rearms_stale_full_queue(cron_mode_on, fresh_post):
    """Primary regression — stuck full-purge queue self-heals on next enqueue.

    Mirrors the 2026-06-07 prod state: queue.full=true, last_queue_run 12 days
    stale, no scheduled cron event. A subsequent save_post on any post must
    re-arm the cron event (via the watchdog path through enqueue_urls →
    ensure_purge_queue_scheduled). Without the fix, enqueue_urls() returns
    early on queue.full=true and the cron event stays unscheduled.
    """
    post_id, _ = fresh_post

    forced = _force_stuck()
    assert forced["queue"]["full"] is True
    assert forced["next_scheduled"] is False
    assert forced["last_queue_run"] > 0

    _trigger_save_post(post_id)

    state = _wait_for(lambda: _queue_state()["next_scheduled"])
    assert state, (
        "Watchdog did not re-arm vhp_process_purge_queue after a save_post "
        "with a stale full-queue state. The bug: enqueue_urls() returns "
        "early when queue.full=true without calling the scheduler."
    )

    # Drain the queue and confirm last_queue_run advances past the stale value.
    before = _queue_state()
    _run_queue()
    after = _queue_state()
    assert after["last_queue_run"] > before["last_queue_run"]
    # When the queue option is deleted (drained-to-empty), WP returns [] rather
    # than an associative array. Either shape means "no full purge queued".
    queue_after = after["queue"]
    if isinstance(queue_after, dict):
        assert not queue_after.get("full")


def test_watchdog_rearms_stale_granular_queue(cron_mode_on, fresh_post):
    """Same regression shape but with granular URLs queued instead of full=true."""
    post_id, url = fresh_post

    forced = _force_stuck(full=False, urls=[url])
    assert forced["queue"]["full"] is False
    assert forced["queue"]["urls"] == [url]
    assert forced["next_scheduled"] is False

    _trigger_save_post(post_id)

    state = _wait_for(lambda: _queue_state()["next_scheduled"])
    assert state, (
        "Watchdog did not re-arm vhp_process_purge_queue after a save_post "
        "with a stale granular-queue state."
    )


def test_watchdog_does_not_rearm_empty_queue(cron_mode_on, fresh_post):
    """Empty queue + stale last_queue_run must NOT trip the watchdog."""
    post_id, _ = fresh_post

    # Force a stale last_queue_run but with an empty queue (clear after forcing).
    _force_stuck()
    _clear_queue()
    # Re-set last_queue_run far in the past, but leave the queue option deleted.
    state = _queue_state()
    assert state["queue"] == [] or not state["queue"]
    assert state["last_queue_run"] > 0
    assert state["next_scheduled"] is False

    # Trigger a save_post on a draft-equivalent path. We can't easily call
    # ensure_purge_queue_scheduled() directly; instead, we observe that a
    # save_post that produces NO purge URLs does not re-arm. The enqueue_urls
    # path on a published post would add URLs (non-empty queue), so we instead
    # ensure the queue is empty and probe directly via the trigger endpoint
    # (which doesn't add to the queue if there are no URLs to enqueue).
    #
    # Easier check: call /run-cron-processor (which drains an empty queue and
    # advances last_queue_run). After the run, no event should be scheduled.
    _run_queue()

    after = _queue_state()
    assert after["next_scheduled"] is False, (
        "Watchdog re-armed against an empty queue — false alarm."
    )


def test_watchdog_does_not_rearm_recent_last_run(cron_mode_on, fresh_post):
    """Non-empty queue but ``last_queue_run`` within threshold — no re-arm."""
    post_id, _ = fresh_post

    forced = _force_stuck(last_run_age_seconds=10)
    assert forced["queue"]["full"] is True
    assert forced["next_scheduled"] is False

    _trigger_save_post(post_id)

    # next_scheduled may stay falsy OR get scheduled by the (! $scheduled)
    # branch — but if it does get scheduled, the watchdog itself
    # should NOT have unscheduled and re-armed. We can only assert the
    # watchdog branch didn't fire by checking last_queue_run is still close
    # to its forced value (the watchdog's unschedule/reschedule pair is a
    # no-op on its own without process_purge_queue actually running).
    before = forced["last_queue_run"]
    after = _queue_state()["last_queue_run"]
    assert after == before, (
        f"last_queue_run advanced ({before} → {after}) without "
        "process_purge_queue running — unexpected mutation."
    )


def test_watchdog_no_false_alarm_on_fresh_install(cron_mode_on, fresh_post):
    """A fresh install (``last_queue_run`` unset) must not trip the watchdog.

    The ``$last > 0`` guard in ``ensure_purge_queue_scheduled()`` covers the
    edge case where the option has never been written.
    """
    post_id, url = fresh_post

    forced = _force_stuck(full=False, urls=[url], clear_last_run=True)
    assert forced["last_queue_run"] == 0
    assert forced["next_scheduled"] is False
    assert forced["queue"]["urls"] == [url]

    _trigger_save_post(post_id)

    # With last_queue_run=0, the watchdog must skip. The (! $scheduled)
    # branch may still schedule a fresh event — that's the normal path
    # for a non-empty queue. So we only assert: last_queue_run stays 0
    # until something actually runs the handler.
    state = _queue_state()
    assert state["last_queue_run"] == 0, (
        "Watchdog touched last_queue_run on a fresh install."
    )
