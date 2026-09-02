"""Manual admin-bar purges must run immediately even in cron mode."""

import requests

from conftest import API_BASE, fresh_post
from test_cron_purging import _head, _header, _set_cron_mode, _clear_queue


def _simulate_manual_purge(purge_type: str, url: str = None):
    """Simulate an admin bar manual purge action."""
    payload = {"type": purge_type}
    if url:
        payload["url"] = url
    r = requests.post(
        f"{API_BASE}/simulate-manual-purge",
        json=payload,
    )
    r.raise_for_status()
    return r.json()


def test_manual_purge_all_immediate_even_in_cron_mode(fresh_post):
    """Manual 'Purge All' should execute immediately, bypassing cron queue.

    When a user clicks 'Purge Cache (All Pages)' in the admin bar, the purge
    should happen immediately regardless of cron mode settings. This is a
    single regex request with no batching benefit, and users expect immediate
    results from manual actions.
    """
    # Force cron-mode on and start with a clean queue.
    _set_cron_mode("force_on")
    _clear_queue()

    post_id, url = fresh_post

    # Warm cache so we can verify purge works.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    r1 = _head(url)
    assert _header(r1, "X-Cache") == "HIT"

    # Trigger manual "Purge All" via simulated admin bar click.
    result = _simulate_manual_purge("all")

    # Verify purge was executed immediately (captured headers).
    assert result["purge_captured"], "Manual purge should have executed immediately"
    assert result["captured_count"] > 0, "Should have captured at least one purge request"

    # Verify the queue is still empty (not queued).
    queue = result["queue_after"]
    assert not queue.get("full", False), "Queue should NOT have full purge scheduled"
    assert not queue.get("urls", []), "Queue should NOT have URLs scheduled"

    # Verify cache was actually purged - should be MISS again.
    r2 = _head(url)
    assert _header(r2, "X-Cache") == "MISS", "Cache should be invalidated after manual purge"

    # Reset for other tests.
    _set_cron_mode("force_off")
    _clear_queue()


def test_manual_purge_url_immediate_even_in_cron_mode(fresh_post):
    """Manual 'Purge This Page' should execute immediately, bypassing cron queue.

    When a user clicks 'Purge Cache (this page)' for a specific URL, it should
    happen immediately regardless of cron mode. Single URL purges have no
    batching benefit.
    """
    # Force cron-mode on and start with a clean queue.
    _set_cron_mode("force_on")
    _clear_queue()

    post_id, url = fresh_post

    # Warm cache.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    r1 = _head(url)
    assert _header(r1, "X-Cache") == "HIT"

    # Trigger manual URL purge via simulated admin bar click.
    result = _simulate_manual_purge("url", url)

    # Verify purge was executed immediately.
    assert result["purge_captured"], "Manual URL purge should have executed immediately"

    # Verify the queue is still empty.
    queue = result["queue_after"]
    assert not queue.get("urls", []), "Queue should NOT have URLs scheduled"

    # Verify cache was purged - should be MISS again.
    r2 = _head(url)
    assert _header(r2, "X-Cache") == "MISS", "Cache should be invalidated after manual URL purge"

    # Reset for other tests.
    _set_cron_mode("force_off")
    _clear_queue()
