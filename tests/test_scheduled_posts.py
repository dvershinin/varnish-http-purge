import datetime
import time
from urllib.parse import urlparse, urlunparse

import requests

from conftest import (
    API_BASE, WP_URL, WP_BACKEND_URL, purge_all_and_wait,
    wait_for_cache_hit, wait_for_cache_miss,
)


def _backend_get(path: str):
    url = f"{WP_BACKEND_URL}{path}"
    resp = requests.get(url, timeout=10)
    resp.raise_for_status()
    return resp


def _run_wp_cron():
    """
    Trigger WordPress cron via wp-cron.php on the backend.

    In real deployments you would normally use:
      wp cron event run --due-now
    but from inside the tester container we exercise the same cron
    machinery by calling wp-cron.php directly.
    """
    url = f"{WP_BACKEND_URL}/wp-cron.php?doing_wp_cron=1"
    resp = requests.get(url, timeout=15)
    resp.raise_for_status()
    return resp


def _create_scheduled_post(seconds_from_now: int = 5):
    now = datetime.datetime.now(datetime.timezone.utc)
    # Schedule short enough to wait for in test, but far enough to be future.
    when = now + datetime.timedelta(seconds=seconds_from_now)
    title = f"Scheduled Post {now.timestamp()}"

    payload = {
        "title": title,
        "content": f"Scheduled content at {when.isoformat()}",
        "status": "future",
        # Use explicit GMT date so WordPress schedules publish_future_post correctly.
        "date_gmt": when.strftime("%Y-%m-%dT%H:%M:%S"),
    }
    resp = requests.post(f"{API_BASE}/post", json=payload, timeout=10)
    resp.raise_for_status()
    data = resp.json()

    return data["id"], title


def test_scheduled_post_publishes_and_purges_via_cron():
    """
    Test that when a scheduled post is published via WP-Cron, the cache is
    properly purged so the new post appears on the front page.

    This test verifies the fix for the reported issue where scheduled posts
    did not auto-flush the Varnish cache when published automatically.
    """
    home = f"{WP_URL}/"

    # Start from a clean cache so we can observe MISS -> HIT cycles.
    # Use purge_all_and_wait to avoid flakiness from purge propagation delays.
    purge_all_and_wait()

    # Warm the home page cache: first request is MISS, second is HIT.
    # Use allow_redirects=False to avoid following WordPress redirects to
    # localhost which isn't reachable from inside the container.
    r0 = requests.get(home, timeout=10, allow_redirects=False)
    # Accept redirect responses (301/302) or 200 - WordPress may redirect to canonical URL.
    assert r0.status_code in (200, 301, 302), f"Expected 200/301/302, got {r0.status_code}"
    assert r0.headers.get("X-Cache") == "MISS", "First home request should be MISS"

    # Wait for cache to warm up reliably
    state = wait_for_cache_hit(home)
    assert state == "HIT", f"Second home request should be HIT, got {state}"

    # Create a scheduled post that will publish in 2 seconds.
    post_id, title = _create_scheduled_post(seconds_from_now=2)

    # Wait for the scheduled time to pass, then run cron.
    time.sleep(3)

    # Drive WordPress cron until the scheduled post is published.
    status = None
    deadline = time.time() + 60
    while time.time() < deadline:
        _run_wp_cron()
        resp = _backend_get(f"/wp-json/wp/v2/posts/{post_id}")
        body = resp.json()
        status = body.get("status")
        if status == "publish":
            break
        time.sleep(1)

    assert status == "publish", "Expected scheduled post to transition to publish via cron"

    # After publish, the home page cache should be purged.
    # Use wait_for_cache_miss to handle purge propagation delays.
    # The purge is triggered by the transition_post_status hook when the post
    # transitions from 'future' to 'publish'.
    state = wait_for_cache_miss(home, max_attempts=30, delay=0.25)
    assert state == "MISS", (
        "Home page should be MISS after scheduled post publish - "
        f"cache should have been purged by transition_post_status hook, got {state}"
    )

    # Second request should be HIT (freshly cached).
    state = wait_for_cache_hit(home)
    assert state == "HIT", f"Home page should be HIT after being re-cached, got {state}"

    # Verify the published post's canonical URL is accessible via Varnish.
    # Get the canonical URL from the REST API.
    post_resp = _backend_get(f"/wp-json/wp/v2/posts/{post_id}")
    post_data = post_resp.json()
    canonical_link = post_data.get("link", "")

    if canonical_link:
        # Rewrite to go through Varnish.
        orig = urlparse(canonical_link)
        dest = urlparse(WP_URL)
        post_url = urlunparse(
            (dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment)
        )
        r_post = requests.get(post_url, timeout=10, allow_redirects=False)
        # Accept 200 or redirect (WordPress may redirect to canonical URL with trailing slash)
        assert r_post.status_code in (200, 301, 302), f"Published post should return 200/301/302, got {r_post.status_code}"


