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

from cache_behavior_helpers import (
    _create_test_post,
    _delete_post,
    _get_page_content,
    _purge_url,
    _update_post_content_bypass_purge,
    _wait_for_cache_hit,
    _wait_for_cache_miss,
)
from conftest import assert_is_wordpress_response


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
