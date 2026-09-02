"""Shared cache test helpers re-exported by conftest."""

import time

import requests

__all__ = [
    "assert_cache_hit",
    "assert_cache_miss",
    "assert_is_wordpress_response",
    "get_headers",
    "purge_all_and_wait",
    "wait_for_cache_hit",
    "wait_for_cache_miss",
    "wait_for_header",
    "wait_for_option_effect",
    "wait_http_ok",
]


def assert_is_wordpress_response(body: str, url: str = None):
    """Assert that the response body is from a WordPress site.

    Checks for common WordPress indicators in the HTML to verify
    we're actually hitting the correct WordPress installation.
    """
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


def assert_cache_hit(url: str, max_attempts: int = 20,
                     delay: float = 0.25) -> None:
    """Assert cache reaches HIT state within timeout. Raises AssertionError on failure."""
    state = wait_for_cache_hit(url, max_attempts, delay)
    assert state == "HIT", (
        f"Expected cache HIT for {url} within {max_attempts * delay}s, got {state}"
    )


def assert_cache_miss(url: str, max_attempts: int = 20,
                      delay: float = 0.25) -> None:
    """Assert cache reaches MISS state within timeout. Raises AssertionError on failure."""
    state = wait_for_cache_miss(url, max_attempts, delay)
    assert state == "MISS", (
        f"Expected cache MISS for {url} within {max_attempts * delay}s, got {state}"
    )


def wait_for_header(url: str, header_name: str, expected_present: bool = True,
                    max_attempts: int = 20, delay: float = 0.25) -> bool:
    """Wait until a header is present or absent on a fresh (MISS) response."""
    from conftest import API_BASE

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
    """Wait for a WordPress option change to take effect in Varnish responses."""
    from conftest import API_BASE

    time.sleep(0.5)
    requests.post(f"{API_BASE}/purge", json={"url": url})

    last_status = None
    last_cache = None
    last_has_header = None
    miss_count = 0
    hit_count = 0

    start = time.time()
    while time.time() - start < timeout:
        time.sleep(0.3)
        requests.post(f"{API_BASE}/purge", json={"url": url})
        time.sleep(0.2)

        r = requests.head(url, allow_redirects=False)
        last_status = r.status_code
        last_cache = r.headers.get("X-Cache")
        last_has_header = header_name in r.headers

        if r.status_code not in (200, 301, 302):
            continue
        if last_cache != "MISS":
            hit_count += 1
            continue

        miss_count += 1
        if expected_present and last_has_header:
            return
        if not expected_present and not last_has_header:
            return

    state_desc = "present" if expected_present else "absent"
    raise RuntimeError(
        f"Timeout waiting for {header_name} to be {state_desc} on {url}. "
        f"Last: status={last_status}, X-Cache={last_cache}, "
        f"header_present={last_has_header}, hits={hit_count}, misses={miss_count}"
    )


def purge_all_and_wait(max_attempts: int = 20, delay: float = 0.25):
    """Purge all cache and wait until cache is confirmed cleared."""
    from conftest import API_BASE, WP_URL

    probe_url = f"{WP_URL}/?_purge_probe={time.time()}"
    requests.head(probe_url, allow_redirects=False)

    resp = requests.post(f"{API_BASE}/purge", json={"all": True})
    resp.raise_for_status()

    state = wait_for_cache_miss(probe_url, max_attempts=max_attempts, delay=delay)
    assert state == "MISS", (
        f"Cache did not clear after purge_all. "
        f"Expected MISS but got {state} for {probe_url}"
    )
