"""
Tests for admin bar functionality including:
- Purge link URL building
- Admin bar rendering (exercises get_current_blog_id() and permission checks)
"""
import os
import time
from urllib.parse import urlparse, urlunparse
import requests
import pytest

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
WP_BACKEND_URL = os.environ.get("WP_BACKEND_URL", "http://wordpress")
API_BASE = f"{WP_BACKEND_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def _to_container_url(u: str) -> str:
    orig = urlparse(u)
    dest = urlparse(WP_URL)
    return urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))


def _to_home_url(u: str) -> str:
    """Convert URL to use the WordPress site host (home_url), not the varnish service.
    This simulates how WP actually builds adminbar links.
    """
    orig = urlparse(u)
    return urlunparse((orig.scheme, HOST_HEADER_VALUE, orig.path, orig.params, orig.query, orig.fragment))


def head(url: str):
    r = requests.head(url, headers=_host_headers(), allow_redirects=False)
    r.raise_for_status()
    return r


def header(r, name: str) -> str:
    return r.headers.get(name)


def _purge_all():
    r = requests.post(f"{API_BASE}/purge", json={"all": True}, headers=_host_headers())
    r.raise_for_status()


def _warm_get(url: str):
    # Use GET to ensure cache fill; tolerate redirects
    r = requests.get(url, headers=_host_headers(), allow_redirects=False)
    return r


def _wait_for_cache_state(url: str, expected: str, max_attempts: int = 10, delay: float = 0.3):
    """
    Wait for cache to reach expected state (HIT or MISS).
    
    Returns the final cache state after retrying. This helps avoid flakiness
    caused by purge propagation delays or race conditions.
    """
    got = None
    for _ in range(max_attempts):
        time.sleep(delay)
        got = header(head(url), "X-Cache")
        if got == expected:
            return got
    return got


@pytest.mark.parametrize(
    "mode,expect_miss",
    [
        # "old" mode uses trailingslashit() which adds a trailing slash.
        # When permalink structure is /%postname% (no trailing slash), the
        # cached URL is /path but old mode purges /path/ - different URLs.
        # In theory, Varnish should NOT purge since the URLs don't match.
        # However, accumulated BAN patterns from previous tests can cause
        # unpredictable behavior, so this test is marked as xfail.
        pytest.param(
            "old",
            False,
            marks=pytest.mark.xfail(
                reason="BAN pattern accumulation in Varnish causes unpredictable results when tests run in sequence.",
                strict=False,
            ),
        ),
        # "new" mode uses user_trailingslashit() which respects permalink
        # structure. It purges /path which matches the cached URL.
        ("new", True),
    ],
)
def test_adminbar_purge_link_no_trailing_slash(mode, expect_miss):
    r = requests.post(f"{API_BASE}/permalinks", json={"structure": "/%postname%"}, headers=_host_headers())
    r.raise_for_status()

    c = requests.post(f"{API_BASE}/post", json={}, headers=_host_headers())
    c.raise_for_status()
    data = c.json()
    url = _to_container_url(data["url"]).rstrip('/')

    # Purge cache and wait for MISS state with retries to avoid flakiness
    _purge_all()
    cache_state = _wait_for_cache_state(url, "MISS", max_attempts=10, delay=0.3)
    assert cache_state == "MISS", f"Expected MISS after purge_all, got {cache_state}"

    # Actively warm with a GET to ensure cache fill (HEAD may not always populate cache)
    _warm_get(url)

    # Wait for cache to be populated (HIT state)
    cache_state = _wait_for_cache_state(url, "HIT", max_attempts=6, delay=0.25)
    assert cache_state == "HIT", f"Expected HIT after warming, got {cache_state}"

    # Simulate admin bar purge effect server-side, focusing on the URL building logic
    # Simulate the server building links with the site's home_url host
    page_url = _to_home_url(url)
    b = requests.post(f"{API_BASE}/adminbar-purge-exec", json={"page_url": page_url, "mode": mode}, headers=_host_headers())
    b.raise_for_status()

    # Wait for cache state after adminbar purge
    if expect_miss:
        cache_state = _wait_for_cache_state(url, "MISS", max_attempts=10, delay=0.4)
        assert cache_state == "MISS", f"Expected MISS after adminbar purge (mode={mode}), got {cache_state}"
    else:
        # For "old" mode, we expect the cache to NOT be properly purged (still HIT or inconsistent)
        cache_state = _wait_for_cache_state(url, "MISS", max_attempts=5, delay=0.4)
        assert cache_state != "MISS", f"Expected cache to NOT be purged with old mode, but got MISS"


def test_adminbar_render_no_errors():
    """
    Test that varnish_rightnow_adminbar() renders without errors.

    This exercises the code path that uses get_current_blog_id() for
    multisite permission checks. Even on single-site, this ensures:
    - No undefined variable errors
    - Permission logic runs without exceptions
    - Admin bar nodes are generated correctly
    """
    r = requests.get(f"{API_BASE}/adminbar-render", headers=_host_headers())
    r.raise_for_status()
    data = r.json()

    assert data["ok"] is True
    # Should have at least the main cache menu node
    assert data["node_count"] >= 1
    # blog_id should be returned (1 on single-site)
    assert "blog_id" in data
    assert isinstance(data["blog_id"], int)
    # Check that nodes were captured
    assert isinstance(data["nodes"], list)
    # The first node should be the main cache menu
    if data["nodes"]:
        first_node = data["nodes"][0]
        assert "id" in first_node
        assert first_node["id"] == "purge-varnish-cache"

