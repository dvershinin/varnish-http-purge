"""
End-to-End Cache Behavior Tests

These tests verify the complete cache workflow:
1. Content gets cached
2. Cache serves stale content after DB modification (bypassing purge)
3. Purge correctly invalidates the cache
4. Fresh content is served after purge

This mirrors the "End-to-End Cache & Purge Test" feature in the admin UI.
"""

import os
import time
import requests
from urllib.parse import urlparse, urlunparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
API_BASE = f"{WP_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
# Host header value is determined dynamically by getting the site URL from a created post.
# This is cached after first use. Initially None.
_HOST_HEADER_VALUE = None


def _get_host_header_from_url(url: str) -> str:
    """Extract host:port from URL for use as Host header."""
    parsed = urlparse(url)
    if parsed.port and parsed.port != 80:
        return f"{parsed.hostname}:{parsed.port}"
    return parsed.hostname or "localhost"


def _host_headers(url: str = None):
    """Get headers with Host header.
    
    If url is provided, extract the host from it. Otherwise use cached value.
    """
    global _HOST_HEADER_VALUE
    if url:
        _HOST_HEADER_VALUE = _get_host_header_from_url(url)
    if _HOST_HEADER_VALUE:
        return {"Host": _HOST_HEADER_VALUE}
    # Fallback for API calls before we know the site URL
    return {"Host": "localhost:8080"}


def _translate_url(url: str) -> str:
    """Translate a localhost URL to the internal varnish URL.

    When running in Docker, WordPress returns URLs like http://localhost:PORT/...
    but from inside the tester container we need to connect to http://varnish/...
    """
    parsed = urlparse(url)
    target = urlparse(WP_URL)

    # If URL is pointing to localhost but WP_URL points to varnish, translate
    if parsed.hostname == "localhost" and target.hostname == "varnish":
        # Replace hostname with varnish, keep the path
        new_parsed = parsed._replace(netloc="varnish")
        return urlunparse(new_parsed)

    return url


def _get_page_content(url: str) -> tuple:
    """Fetch page and return (body, headers)."""
    internal_url = _translate_url(url)

    # Don't follow redirects automatically - they might point to localhost
    r = requests.get(internal_url, allow_redirects=False, headers=_host_headers())

    # Handle redirects manually, translating URLs
    max_redirects = 10
    while r.status_code in (301, 302, 303, 307, 308) and max_redirects > 0:
        redirect_url = r.headers.get("Location", "")
        redirect_url = _translate_url(redirect_url)
        r = requests.get(redirect_url, allow_redirects=False, headers=_host_headers())
        max_redirects -= 1

    r.raise_for_status()
    return r.text, r.headers


def _create_test_post(marker: str) -> dict:
    """Create a test post via REST API.
    
    The marker is placed ONLY in content, not in title, so we can track
    content changes independently of the title.
    """
    global _HOST_HEADER_VALUE
    resp = requests.post(
        f"{API_BASE}/post",
        json={
            "title": "VHP Cache Test Post",
            "content": f"CONTENT_MARKER:{marker}:END_MARKER",
            "status": "publish",
        },
        headers=_host_headers(),
    )
    resp.raise_for_status()
    data = resp.json()

    # Update the Host header value from the returned URL
    if "url" in data:
        _HOST_HEADER_VALUE = _get_host_header_from_url(data["url"])

    return data


def _update_post_content_bypass_purge(post_id: int, new_marker: str):
    """Update post content directly in DB, bypassing WordPress hooks.
    
    Uses the same format as _create_test_post for consistency.
    """
    resp = requests.post(
        f"{API_BASE}/update-post-bypass",
        json={
            "post_id": post_id,
            "content": f"CONTENT_MARKER:{new_marker}:END_MARKER",
        },
        headers=_host_headers(),
    )
    resp.raise_for_status()
    return resp.json()


def _purge_url(url: str):
    """Trigger purge for a specific URL.
    
    Note: The URL should be the public URL (as returned by WordPress).
    The REST API will handle the actual purge.
    """
    resp = requests.post(
        f"{API_BASE}/purge",
        json={"url": url},
        headers=_host_headers(),
    )
    resp.raise_for_status()
    return resp.json()


def _delete_post(post_id: int):
    """Delete a test post."""
    resp = requests.post(
        f"{API_BASE}/delete-post",
        json={"post_id": post_id},
        headers=_host_headers(),
    )
    resp.raise_for_status()


class TestCacheBehavior:
    """End-to-end cache behavior tests."""

    def test_cache_stores_content(self):
        """Verify that content gets cached after first request."""
        # Create test post
        marker = f"TEST_MARKER_{int(time.time())}"
        post = _create_test_post(marker)
        post_id = post["id"]
        post_url = post["url"]

        try:
            # Purge to start clean
            _purge_url(post_url)
            time.sleep(0.5)

            # First request - should be MISS
            body1, headers1 = _get_page_content(post_url)
            assert marker in body1, "Marker should be in page content"
            assert headers1.get("X-Cache") == "MISS", "First request should be MISS"

            # Second request - should be HIT
            body2, headers2 = _get_page_content(post_url)
            assert marker in body2, "Marker should still be in page content"
            assert headers2.get("X-Cache") == "HIT", "Second request should be HIT"

        finally:
            _delete_post(post_id)

    def test_cache_serves_stale_content_after_db_update(self):
        """Verify cache serves stale content when DB is updated without purge."""
        # Create test post
        original_marker = f"ORIGINAL_{int(time.time())}"
        post = _create_test_post(original_marker)
        post_id = post["id"]
        post_url = post["url"]

        try:
            # Prime the cache
            _purge_url(post_url)
            time.sleep(0.5)
            _get_page_content(post_url)  # MISS
            _get_page_content(post_url)  # HIT - now cached

            # Update content directly in DB (bypassing purge hooks)
            new_marker = f"UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)
            time.sleep(0.3)

            # Request page - should still serve OLD (cached) content
            body, headers = _get_page_content(post_url)

            # This is the key assertion: cache should serve stale content
            assert original_marker in body, (
                "Cache should serve stale (old) content after DB update without purge"
            )
            assert new_marker not in body, (
                "New content should NOT be served until cache is purged"
            )
            assert headers.get("X-Cache") == "HIT", "Should be cache HIT"

        finally:
            _delete_post(post_id)

    def test_purge_invalidates_cache(self):
        """Verify that purge correctly invalidates cached content."""
        # Create test post
        original_marker = f"ORIGINAL_{int(time.time())}"
        post = _create_test_post(original_marker)
        post_id = post["id"]
        post_url = post["url"]

        try:
            # Prime the cache
            _purge_url(post_url)
            time.sleep(0.5)
            _get_page_content(post_url)  # MISS
            body_cached, _ = _get_page_content(post_url)  # HIT
            assert original_marker in body_cached

            # Update content directly in DB
            new_marker = f"UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)
            time.sleep(0.3)

            # Verify cache still serves old content
            body_stale, headers_stale = _get_page_content(post_url)
            assert original_marker in body_stale, "Cache should still serve old content"
            assert headers_stale.get("X-Cache") == "HIT"

            # Now trigger purge
            _purge_url(post_url)
            time.sleep(0.5)

            # Request again - should be MISS and serve new content
            body_fresh, headers_fresh = _get_page_content(post_url)

            assert new_marker in body_fresh, (
                "After purge, new content should be served"
            )
            assert original_marker not in body_fresh, (
                "After purge, old content should NOT be served"
            )
            assert headers_fresh.get("X-Cache") == "MISS", (
                "After purge, should be cache MISS"
            )

        finally:
            _delete_post(post_id)

    def test_full_e2e_workflow(self):
        """Complete end-to-end test matching the admin UI workflow."""
        # Step 1: Create test post
        original_marker = f"E2E_ORIGINAL_{int(time.time())}"
        post = _create_test_post(original_marker)
        post_id = post["id"]
        post_url = post["url"]

        try:
            # Step 2: Prime cache
            _purge_url(post_url)
            time.sleep(0.5)

            body1, h1 = _get_page_content(post_url)
            assert original_marker in body1, "Step 2: Content should be in page"
            # First hit might be MISS, that's expected

            body2, h2 = _get_page_content(post_url)
            assert original_marker in body2, "Step 2: Content should still be in page"

            # Step 3: Modify content bypassing purge
            new_marker = f"E2E_UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)
            time.sleep(0.5)

            # Step 4: Verify caching works (stale content served)
            body3, h3 = _get_page_content(post_url)
            caching_works = original_marker in body3 and new_marker not in body3

            assert caching_works, (
                f"Step 4 FAILED: Caching is not working! "
                f"Expected stale content with '{original_marker}', "
                f"but got content with new marker present: {new_marker in body3}"
            )

            # Step 5: Trigger purge
            _purge_url(post_url)
            time.sleep(0.5)

            # Step 6: Verify purge worked (fresh content served)
            body4, h4 = _get_page_content(post_url)
            purge_works = new_marker in body4 and original_marker not in body4

            assert purge_works, (
                f"Step 6 FAILED: Purge is not working! "
                f"Expected fresh content with '{new_marker}', "
                f"but stale content is still being served."
            )

        finally:
            # Step 7: Cleanup
            _delete_post(post_id)


class TestCacheTestPostsBypass:
    """Verify that cache test posts bypass normal purge logic."""

    def test_test_post_meta_set(self):
        """Verify test posts have the bypass meta flag."""
        # This tests the WordPress side - we need an endpoint for this
        # For now, we verify the flow works by checking no extra purges happen
        pass  # Placeholder - would need a REST endpoint to verify meta

