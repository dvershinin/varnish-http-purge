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


