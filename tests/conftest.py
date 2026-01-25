import os
import time
import requests
import pytest
from urllib.parse import urlparse, urlunparse

# All requests go through Varnish at http://varnish:6081 (internal Docker URL).
# WordPress is installed with this same URL, so no Host header tricks needed.
# The test mu-plugin sends Cache-Control: no-cache headers so API responses are never cached.
WP_URL = os.environ.get("WP_URL", "http://varnish:6081")
# WP_BACKEND_URL is kept for backwards compatibility but points to the same place.
WP_BACKEND_URL = WP_URL
API_BASE = f"{WP_URL}/wp-json/test/v1"

# Standard polling constants for cache state helpers
DEFAULT_POLL_DELAY = 0.25      # seconds between polls
DEFAULT_POLL_ATTEMPTS = 20     # 20 * 0.25 = 5 seconds max


def assert_is_wordpress_response(body: str, url: str = None):
    """Assert that the response body is from a WordPress site.

    Checks for common WordPress indicators in the HTML to verify
    we're actually hitting the correct WordPress installation.
    """
    # Check for WordPress-specific markers
    wp_indicators = [
        "wp-content",
        "wp-includes",
        "WordPress",
        'name="generator" content="WordPress',
    ]

    found_indicators = [ind for ind in wp_indicators if ind in body]

    assert found_indicators, (
        f"Response does not appear to be from WordPress. "
        f"URL: {url or 'unknown'}. "
        f"Expected at least one of: {wp_indicators}. "
        f"Body preview: {body[:500]}..."
    )


def wait_http_ok(url: str, timeout: float = 60.0, headers=None, accept_codes=None):
    """Wait until an HTTP endpoint responds with one of acceptable status codes.
    Defaults to (200, 301, 302, 403, 503) to be tolerant during warmup behind Varnish.
    """
    if accept_codes is None:
        accept_codes = {200, 301, 302, 403, 503}
    start = time.time()
    while time.time() - start < timeout:
        try:
            r = requests.head(url, timeout=3, allow_redirects=False, headers=headers or {})
            if r.status_code in accept_codes:
                return
        except Exception:
            pass
        time.sleep(1)
    raise RuntimeError(f"Timeout waiting for {url}")


@pytest.fixture(scope="session", autouse=True)
def ensure_up():
    # Allow fast-running or sandboxed environments to skip the long warmup checks
    # by setting VHP_SKIP_ENSURE_UP=1. Locally and in CI this should normally
    # be left unset so we still verify that WordPress/Varnish are reachable.
    if os.environ.get("VHP_SKIP_ENSURE_UP") == "1":
        return

    # Ensure WordPress backend is up and Varnish (public URL) responds, too
    # Be tolerant to redirects/403/503 during warmup
    try:
        wait_http_ok(WP_BACKEND_URL, timeout=120.0)
    except Exception:
        # In environments without a distinct backend, WP_BACKEND_URL may be same as WP_URL
        pass
    try:
        wait_http_ok(WP_URL, timeout=120.0)
    except Exception:
        # Allow tests to proceed; individual tests include retries
        pass

    # By default, run the suite with cron-based purging forced off so behaviour
    # matches the historical synchronous semantics. Individual tests that need
    # cron-mode can toggle it explicitly via the helper endpoint.
    try:
        resp = requests.post(
            f"{API_BASE}/cron-mode",
            json={"mode": "force_off"},
            timeout=5,
        )
        resp.raise_for_status()
    except Exception:
        # If the helper endpoint is unavailable for any reason, continue;
        # tests that rely on it will fail with clearer errors.
        pass


@pytest.fixture()
def fresh_post():
    r = requests.post(f"{API_BASE}/post", json={})
    r.raise_for_status()
    data = r.json()
    # WordPress URL already matches WP_URL (http://varnish:6081), but ensure consistency
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    new_url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))
    return data["id"], new_url


@pytest.fixture()
def reset_plugin_options():
    """
    Reset all VHP plugin options to known defaults before and after test.

    This fixture ensures test isolation by:
    - Resetting options to defaults before the test runs
    - Resetting again after the test completes (cleanup)

    Default state:
    - vhp_varnish_ip: '' (empty)
    - vhp_varnish_devmode: {'active': False, 'expire': 0}
    - vhp_varnish_max_posts_before_all: 50
    - vhp_varnish_use_tags: 0 (disabled)
    - vhp_varnish_force_cron_mode: 'off'
    - No custom purge headers configured
    - No pending purge queue
    """
    def _reset():
        resp = requests.post(f"{API_BASE}/reset-options", json={}, timeout=10)
        resp.raise_for_status()
        return resp.json()

    # Reset before test
    _reset()
    yield _reset
    # Reset after test (cleanup)
    _reset()


def get_headers(url: str):
    r = requests.head(url, allow_redirects=False)
    r.raise_for_status()
    return {k.title(): v for k, v in r.headers.items()}


def wait_for_cache_miss(url: str, max_attempts: int = 20, delay: float = 0.25) -> str:
    """Wait for cache to show MISS state (after purge). Returns final X-Cache value."""
    state = None
    for _ in range(max_attempts):
        r = requests.head(url, allow_redirects=False)
        state = r.headers.get("X-Cache")
        if state == "MISS":
            return state
        time.sleep(delay)
    return state


def wait_for_cache_hit(url: str, max_attempts: int = 20, delay: float = 0.25) -> str:
    """Wait for cache to show HIT state (warmed up). Returns final X-Cache value."""
    state = None
    for _ in range(max_attempts):
        r = requests.head(url, allow_redirects=False)
        state = r.headers.get("X-Cache")
        if state == "HIT":
            return state
        time.sleep(delay)
    return state


def assert_cache_hit(url: str, max_attempts: int = DEFAULT_POLL_ATTEMPTS,
                     delay: float = DEFAULT_POLL_DELAY) -> None:
    """Assert cache reaches HIT state within timeout. Raises AssertionError on failure."""
    state = wait_for_cache_hit(url, max_attempts, delay)
    assert state == "HIT", (
        f"Expected cache HIT for {url} within {max_attempts * delay}s, got {state}"
    )


def assert_cache_miss(url: str, max_attempts: int = DEFAULT_POLL_ATTEMPTS,
                      delay: float = DEFAULT_POLL_DELAY) -> None:
    """Assert cache reaches MISS state within timeout. Raises AssertionError on failure."""
    state = wait_for_cache_miss(url, max_attempts, delay)
    assert state == "MISS", (
        f"Expected cache MISS for {url} within {max_attempts * delay}s, got {state}"
    )


def wait_for_header(url: str, header_name: str, expected_present: bool = True,
                    max_attempts: int = 20, delay: float = 0.25) -> bool:
    """Wait until a header is present or absent on a fresh (MISS) response.

    Args:
        url: The URL to check.
        header_name: The header name to look for.
        expected_present: True to wait for header to be present, False to wait for absence.
        max_attempts: Maximum polling attempts.
        delay: Delay between attempts in seconds.

    Returns:
        True if the expected state was observed, False on timeout.
    """
    for _ in range(max_attempts):
        requests.post(f"{API_BASE}/purge", json={"url": url})
        time.sleep(delay)
        r = requests.head(url, allow_redirects=False)
        if r.status_code not in (200, 301, 302):
            continue
        if r.headers.get("X-Cache") != "MISS":
            continue
        has_header = header_name in r.headers
        if expected_present and has_header:
            return True
        if not expected_present and not has_header:
            return True
    return False


def wait_for_option_effect(url: str, header_name: str, expected_present: bool,
                           timeout: float = 15.0) -> None:
    """Wait for a WordPress option change to take effect in Varnish responses.

    This function blocks until we observe the expected header state on a MISS
    response. It does an initial purge to evict any cached response with old state,
    then polls with purge+check cycles until the expected state is observed.

    Args:
        url: The URL to check.
        header_name: The header name to look for.
        expected_present: True to wait for header to be present, False for absence.
        timeout: Maximum time to wait in seconds.

    Raises:
        RuntimeError: If the expected state is not observed within the timeout.
    """
    # Initial delay to let database transaction commit and any caches invalidate.
    time.sleep(0.3)

    # Initial purge to evict any cached response with old state.
    requests.post(f"{API_BASE}/purge", json={"url": url})

    start = time.time()
    while time.time() - start < timeout:
        # Delay between attempts - give Varnish time to process purge.
        time.sleep(0.3)

        # Purge again to ensure we get a fresh MISS.
        requests.post(f"{API_BASE}/purge", json={"url": url})
        time.sleep(0.2)

        r = requests.head(url, allow_redirects=False)
        # Accept 200 or 301/302 (home may redirect).
        if r.status_code not in (200, 301, 302):
            continue

        # We need a MISS to see the fresh backend response.
        if r.headers.get("X-Cache") != "MISS":
            continue

        has_header = header_name in r.headers
        if expected_present and has_header:
            return
        if not expected_present and not has_header:
            return

    state_desc = "present" if expected_present else "absent"
    raise RuntimeError(f"Timeout waiting for {header_name} to be {state_desc} on {url}")


def purge_all_and_wait(max_attempts: int = 20, delay: float = 0.25):
    """
    Purge all cache and wait until cache is confirmed cleared.

    Uses a unique probe URL with a random query parameter to verify purge
    succeeded without warming any URLs that tests might care about.

    Args:
        max_attempts: Maximum polling attempts.
        delay: Delay between polling attempts in seconds.

    Raises:
        AssertionError: If cache doesn't show MISS within timeout.
    """
    # Use a unique probe URL so polling doesn't interfere with test URLs.
    # The random query parameter ensures this URL is unique to this purge operation.
    probe_url = f"{WP_URL}/?_purge_probe={time.time()}"

    # First, warm the probe URL so we have something to check against
    requests.head(probe_url, allow_redirects=False)

    # Issue the purge
    resp = requests.post(f"{API_BASE}/purge", json={"all": True})
    resp.raise_for_status()

    # Poll until we see MISS
    state = wait_for_cache_miss(probe_url, max_attempts=max_attempts, delay=delay)
    assert state == "MISS", (
        f"Cache did not clear after purge_all. "
        f"Expected MISS but got {state} for {probe_url}"
    )


