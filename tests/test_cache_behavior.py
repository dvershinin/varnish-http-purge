"""
End-to-End Cache Behavior Tests

These tests verify the complete cache workflow:
1. Content gets cached
2. Cache serves stale content after DB modification (bypassing purge)
3. Purge correctly invalidates the cache
4. Fresh content is served after purge

This mirrors the "End-to-End Cache & Purge Test" feature in the admin UI.
"""

import time
import requests
from urllib.parse import urlparse, urlunparse

from conftest import (
    WP_URL,
    API_BASE,
    assert_is_wordpress_response,
    wait_for_cache_hit,
    wait_for_cache_miss,
)


def _translate_url(url: str) -> str:
    """Ensure URL uses the internal varnish address.

    WordPress returns URLs using its configured site URL (http://varnish:6081).
    This helper normalizes URLs to ensure consistency.
    """
    parsed = urlparse(url)
    target = urlparse(WP_URL)
    new_parsed = parsed._replace(netloc=target.netloc, scheme=target.scheme)
    return urlunparse(new_parsed)


def _get_page_content(url: str) -> tuple:
    """Fetch page and return (body, headers)."""
    internal_url = _translate_url(url)

    # Don't follow redirects automatically - they might point to localhost
    r = requests.get(internal_url, allow_redirects=False)

    # Handle redirects manually, translating URLs
    max_redirects = 10
    while r.status_code in (301, 302, 303, 307, 308) and max_redirects > 0:
        redirect_url = r.headers.get("Location", "")
        redirect_url = _translate_url(redirect_url)
        r = requests.get(redirect_url, allow_redirects=False)
        max_redirects -= 1

    r.raise_for_status()
    return r.text, r.headers


def _create_test_post(marker: str) -> dict:
    """Create a test post via REST API.

    The marker is placed ONLY in content, not in title, so we can track
    content changes independently of the title.
    """
    resp = requests.post(
        f"{API_BASE}/post",
        json={
            "title": "VHP Cache Test Post",
            "content": f"CONTENT_MARKER:{marker}:END_MARKER",
            "status": "publish",
        },
    )
    resp.raise_for_status()
    return resp.json()


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
    )
    resp.raise_for_status()
    return resp.json()


def _delete_post(post_id: int):
    """Delete a test post."""
    resp = requests.post(
        f"{API_BASE}/delete-post",
        json={"post_id": post_id},
    )
    resp.raise_for_status()


def _wait_for_cache_hit(url: str, max_attempts: int = 20, delay: float = 0.25) -> str:
    """Wait for cache HIT on a URL, handling URL translation."""
    internal_url = _translate_url(url)
    return wait_for_cache_hit(internal_url, max_attempts, delay)


def _wait_for_cache_miss(url: str, max_attempts: int = 20, delay: float = 0.25) -> str:
    """Wait for cache MISS on a URL, handling URL translation."""
    internal_url = _translate_url(url)
    return wait_for_cache_miss(internal_url, max_attempts, delay)


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

            # Wait for cache to confirm MISS state (purge processed)
            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"After purge, expected MISS but got {state}"

            # Wait for cache to warm up (subsequent requests should HIT)
            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Cache should warm up to HIT, got {state}"

            # Verify content is correct and served from cache
            body, headers = _get_page_content(post_url)
            assert_is_wordpress_response(body, post_url)
            assert marker in body, "Marker should be in page content"
            assert headers.get("X-Cache") == "HIT", "Should be cache HIT"

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
            # Prime the cache: purge, wait for MISS, then wait for HIT
            _purge_url(post_url)

            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"After purge, expected MISS but got {state}"

            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Cache should warm up to HIT, got {state}"

            # Verify original content is cached
            body_cached, _ = _get_page_content(post_url)
            assert_is_wordpress_response(body_cached, post_url)
            assert original_marker in body_cached

            # Update content directly in DB (bypassing purge hooks)
            new_marker = f"UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)

            # Request page - should still serve OLD (cached) content
            # No sleep needed - cache state shouldn't change without purge
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
            # Prime the cache: purge, wait for MISS, then wait for HIT
            _purge_url(post_url)
            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"After purge, expected MISS but got {state}"

            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Cache should warm up to HIT, got {state}"

            body_cached, _ = _get_page_content(post_url)
            assert_is_wordpress_response(body_cached, post_url)
            assert original_marker in body_cached

            # Update content directly in DB
            new_marker = f"UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)

            # Verify cache still serves old content (no sleep needed)
            body_stale, headers_stale = _get_page_content(post_url)
            assert original_marker in body_stale, "Cache should still serve old content"
            assert headers_stale.get("X-Cache") == "HIT"

            # Now trigger purge and wait for MISS state
            _purge_url(post_url)
            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"After purge, expected MISS but got {state}"

            # Wait for cache to warm with fresh content, then verify
            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Cache should warm up with fresh content, got {state}"

            body_fresh, headers_fresh = _get_page_content(post_url)

            assert new_marker in body_fresh, (
                "After purge, new content should be served"
            )
            assert original_marker not in body_fresh, (
                "After purge, old content should NOT be served"
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
            # Step 2: Prime cache - purge, wait for MISS, then wait for HIT
            _purge_url(post_url)

            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"Step 2: After purge, expected MISS but got {state}"

            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Step 2: Cache should warm up to HIT, got {state}"

            # Verify content is cached correctly
            body1, h1 = _get_page_content(post_url)
            assert_is_wordpress_response(body1, post_url)
            assert original_marker in body1, "Step 2: Content should be in page"
            assert h1.get("X-Cache") == "HIT", "Step 2: Content should be served from cache"

            # Step 3: Modify content bypassing purge
            new_marker = f"E2E_UPDATED_{int(time.time())}"
            _update_post_content_bypass_purge(post_id, new_marker)

            # Step 4: Verify caching works (stale content served)
            # No sleep needed - cache state shouldn't change without purge
            body2, h2 = _get_page_content(post_url)

            assert h2.get("X-Cache") == "HIT", (
                f"Step 4: Expected cache HIT but got {h2.get('X-Cache')}. "
                "Cache may have been invalidated unexpectedly."
            )
            assert original_marker in body2, (
                f"Step 4 FAILED: Caching is not working! "
                f"Expected stale content with '{original_marker}', "
                f"but got content with new marker present: {new_marker in body2}"
            )
            assert new_marker not in body2, (
                f"Step 4 FAILED: Cache should serve stale content, "
                f"but new marker '{new_marker}' was found."
            )

            # Step 5: Trigger purge and wait for MISS, then HIT with fresh content
            _purge_url(post_url)

            state = _wait_for_cache_miss(post_url)
            assert state == "MISS", f"Step 5: After purge, expected MISS but got {state}"

            state = _wait_for_cache_hit(post_url)
            assert state == "HIT", f"Step 5: Cache should warm up with fresh content, got {state}"

            # Step 6: Verify purge worked (fresh content served)
            body3, h3 = _get_page_content(post_url)

            assert new_marker in body3, (
                f"Step 6 FAILED: Purge is not working! "
                f"Expected fresh content with '{new_marker}', "
                f"but it was not found in the response."
            )
            assert original_marker not in body3, (
                f"Step 6 FAILED: Stale content still being served. "
                f"Old marker '{original_marker}' should not be present."
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

