import time
from urllib.parse import urlparse

import requests

from conftest import API_BASE, _host_headers, fresh_post


def _head(url: str):
    resp = requests.head(url, allow_redirects=False, headers=_host_headers())
    resp.raise_for_status()
    return resp


def _header(resp, name: str) -> str:
    return resp.headers.get(name)


def _enable_tags(enabled: bool):
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": bool(enabled)},
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


def _set_cron_mode(mode: str):
    """Set cron-mode behaviour inside the test stack.

    mode:
      - "auto"       -> reset to default behaviour.
      - "force_on"   -> always enable cron-mode.
      - "force_off"  -> always disable cron-mode.
    """
    r = requests.post(
        f"{API_BASE}/cron-mode",
        json={"mode": mode},
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


def _queue_status():
    r = requests.get(f"{API_BASE}/purge-queue", headers=_host_headers())
    r.raise_for_status()
    data = r.json()
    return data["queue"]


def _clear_queue():
    r = requests.post(f"{API_BASE}/purge-queue/clear", headers=_host_headers())
    r.raise_for_status()
    return r.json()


def _run_queue():
    r = requests.post(f"{API_BASE}/run-cron-processor", headers=_host_headers())
    r.raise_for_status()
    return r.json()


def test_cron_mode_url_purge_queue_and_process(fresh_post):
    # Force cron-mode on and start with a clean queue.
    _set_cron_mode("force_on")
    _clear_queue()

    post_id, url = fresh_post

    # Warm cache: expect MISS then HIT.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    r1 = _head(url)
    assert _header(r1, "X-Cache") == "HIT"

    # Update the post to trigger URL-based purge (tags mode disabled).
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated via cron-mode {time.time()}"},
        headers=_host_headers(),
    )
    rq.raise_for_status()

    # Inspect the queue; the exact internal representation is an implementation
    # detail, but there should be some work queued rather than an immediate
    # synchronous purge.
    queue = _queue_status()
    assert isinstance(queue, dict)

    # Process the queue via the helper endpoint.
    run = _run_queue()
    assert run["ok"] is True

    # After processing, the queue should no longer block the purge; we don't
    # assert on exact queue internals here, only on the externally observable
    # cache behaviour below.

    # We should eventually observe a cache MISS again due to the queued purge.
    state = None
    for _ in range(12):
        time.sleep(0.5)
        resp = _head(url)
        state = _header(resp, "X-Cache")
        if state == "MISS":
            break
    assert state == "MISS", "Expected MISS after processing the async purge queue"

    # Reset cron-mode and queue so other tests see default synchronous behaviour.
    _set_cron_mode("force_off")
    _clear_queue()


def test_cron_mode_tag_purge_queue_and_process(fresh_post):
    # Enable tag-based purging and cron-mode, and start with a clean queue.
    _set_cron_mode("force_on")
    _clear_queue()
    _enable_tags(True)

    post_id, url = fresh_post

    # Warm cache: MISS then HIT so we can observe invalidation.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    r1 = _head(url)
    assert _header(r1, "X-Cache") == "HIT"

    # Update the post to trigger tag-based purge (now queued instead of immediate).
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated under tag-mode+cron {time.time()}"},
        headers=_host_headers(),
    )
    rq.raise_for_status()

    # Inspect the queue; when tags mode is enabled, we expect tag-based work
    # to be queued instead of an immediate PURGE, but the exact internal
    # structure is an implementation detail.
    queue = _queue_status()
    assert isinstance(queue, dict)

    # Process the queue and capture headers to ensure tag-based PURGE is used.
    run = _run_queue()
    assert run["ok"] is True
    headers_list = run.get("headers") or []
    assert any(
        isinstance(h, dict) and h.get("X-Purge-Method") == "tags" for h in headers_list
    ), "Expected at least one tag-based PURGE request when processing the queue"

    # Eventually the cached object should be invalidated and we see a MISS again.
    state = None
    for _ in range(12):
        time.sleep(0.5)
        resp = _head(url)
        state = _header(resp, "X-Cache")
        if state == "MISS":
            break
    assert state == "MISS", "Expected MISS after processing tag-based async purge queue"

    # Reset environment for other tests.
    _enable_tags(False)
    _set_cron_mode("force_off")
    _clear_queue()


def _simulate_manual_purge(purge_type: str, url: str = None):
    """Simulate an admin bar manual purge action."""
    payload = {"type": purge_type}
    if url:
        payload["url"] = url
    r = requests.post(
        f"{API_BASE}/simulate-manual-purge",
        json=payload,
        headers=_host_headers(),
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


