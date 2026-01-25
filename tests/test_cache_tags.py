import time
import requests
import pytest
from urllib.parse import urlparse, urlunparse

from conftest import (
    API_BASE, WP_URL, get_headers, fresh_post, wait_for_option_effect,
    wait_for_cache_hit, wait_for_cache_miss, assert_cache_hit, assert_cache_miss,
    reset_plugin_options,
)

# Probe URL for verifying tags mode state.
# Use the home page which always gets X-Cache-Tags when tags mode is enabled.
# The home page gets at least the "home" and "site-{id}" tags.
PROBE_URL = f"{WP_URL}/"


@pytest.fixture(autouse=True)
def ensure_clean_state():
    """Reset plugin options before each test to ensure clean state.

    This prevents state leakage from previous tests that might have enabled
    tags mode or cron mode without properly cleaning up.
    """
    # Reset all plugin options to defaults before the test.
    resp = requests.post(f"{API_BASE}/reset-options", json={}, timeout=10)
    resp.raise_for_status()

    yield

    # Clean up after the test by disabling tags mode.
    # This is defensive - tests should do this themselves, but we ensure it here.
    requests.post(f"{API_BASE}/tags-mode", json={"enabled": False}, timeout=10)
    requests.post(f"{API_BASE}/cron-mode", json={"mode": "force_off"}, timeout=10)


def _head(url: str):
    r = requests.head(url, allow_redirects=False)
    r.raise_for_status()
    return r


def _header(resp, name: str) -> str:
    return resp.headers.get(name)


def _enable_tags(enabled: bool, timeout: float = 15.0):
    """Enable or disable tags mode and wait until Varnish reflects the change.

    Uses a longer default timeout (15s) to handle race conditions when running
    in the full test suite where PHP-FPM workers may have stale object cache.
    """
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": bool(enabled)},
    )
    r.raise_for_status()
    result = r.json()

    wait_for_option_effect(
        url=PROBE_URL,
        header_name="X-Cache-Tags",
        expected_present=enabled,
        timeout=timeout,
    )
    return result


def test_cache_tags_header_and_purge_with_tags_mode(fresh_post):
    # Enable tag-based purging.
    _enable_tags(True)

    post_id, url = fresh_post

    # First request should MISS and include cache tags header.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    tags_header = _header(r0, "X-Cache-Tags")
    assert tags_header, "Expected X-Cache-Tags header when tags mode is enabled"

    # Basic sanity: post id tag should be present.
    assert f"p-{post_id}" in tags_header

    # Warm cache to HIT using conftest helper
    assert_cache_hit(url)

    # Update the post to trigger tag-based purge.
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated with tags {time.time()}"},
    )
    rq.raise_for_status()

    # Eventually we should see a MISS again due to tag-based purge.
    assert_cache_miss(url)

    # Disable tag-based purging again so other tests see legacy behaviour.
    _enable_tags(False)


def test_cache_tags_purging_with_many_terms_exercises_batching():
    # Enable tag-based purging so that updates use tag patterns instead of URLs.
    _enable_tags(True)

    # Create a post with many taxonomy terms so that the resulting tag set is large
    # enough to require batching when building X-Cache-Tags-Pattern headers.
    tag_names = [f"tag-{i}" for i in range(40)]
    r_create = requests.post(
        f"{API_BASE}/post",
        json={"tags": tag_names},
    )
    r_create.raise_for_status()
    data = r_create.json()
    post_id = data["id"]

    # Rewrite returned absolute URL to use container address (varnish), same as fresh_post.
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))

    # First request should MISS and include a cache-tags header with many entries.
    r0 = _head(url)
    assert _header(r0, "X-Cache") == "MISS"
    tags_header = _header(r0, "X-Cache-Tags")
    assert tags_header, "Expected X-Cache-Tags header when tags mode is enabled"

    # Basic sanity: we should see the post-id tag.
    # Note: Single posts no longer include term tags (t-{id}) to avoid
    # cross-contamination when purging. Terms are only on archive pages.
    assert f"p-{post_id}" in tags_header

    # Warm to HIT using conftest helper
    assert_cache_hit(url)

    # Update the post to trigger tag-based purge; this will generate batched
    # X-Cache-Tags-Pattern PURGE requests behind the scenes.
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated with many tags {time.time()}"},
    )
    rq.raise_for_status()

    # Eventually we should see a MISS again due to tag-based purge, proving that
    # batching of tag patterns does not prevent correct invalidation.
    assert_cache_miss(url)

    # Disable tag-based purging again so other tests see legacy behaviour.
    _enable_tags(False)


def _create_post_raw():
    """Helper that mirrors the fresh_post fixture but returns both id and rewritten URL."""
    r = requests.post(f"{API_BASE}/post", json={})
    r.raise_for_status()
    data = r.json()
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))
    return data["id"], url


def _purge_by_tag_pattern(pattern: str, exact: bool = True):
    """Send a raw PURGE with a specific X-Cache-Tags-Pattern directly to Varnish.

    This bypasses the plugin's own batching logic and is used as a sanity check
    that the VCL is not over-eager (i.e. a purge for an unrelated tag does not
    evict objects that don't carry that tag).

    Args:
        pattern: The tag pattern to match.
        exact: If True (default), wraps pattern with word boundaries to match
               complete comma-separated tags, not substrings.
               E.g., "p-123" won't match "p-1234".
    """
    if exact:
        # Wrap pattern to match as complete comma-separated value.
        # This ensures "p-123" doesn't accidentally match "p-1234".
        pattern = f"(^|,){pattern}(,|$)"

    headers = {
        "X-Purge-Method": "tags",
        "X-Cache-Tags-Pattern": pattern,
    }
    r = requests.request("PURGE", f"{WP_URL}/", headers=headers)
    r.raise_for_status()


def test_unrelated_tag_purge_does_not_evict_other_object():
    """Sanity check: purging by a specific tag should not evict objects
    that don't carry that tag.

    This guards against test-env or VCL misconfiguration where tag-based
    purges would accidentally behave like a global purge.
    """
    _enable_tags(True)

    # Create two posts; each page gets its own unique post-id tag.
    post_a_id, url_a = _create_post_raw()
    post_b_id, url_b = _create_post_raw()

    # Warm both URLs via Varnish: first MISS, then HIT.
    # Capture tags from MISS responses (backend always sends X-Cache-Tags on MISS).
    r0a = _head(url_a)
    assert _header(r0a, "X-Cache") == "MISS"
    tags_a = _header(r0a, "X-Cache-Tags") or ""

    r1a = _head(url_a)
    assert _header(r1a, "X-Cache") == "HIT"

    r0b = _head(url_b)
    assert _header(r0b, "X-Cache") == "MISS"
    tags_b = _header(r0b, "X-Cache-Tags") or ""

    r1b = _head(url_b)
    assert _header(r1b, "X-Cache") == "HIT"

    # Verify tags from MISS responses contain the expected post-id tags.
    assert f"p-{post_a_id}" in tags_a, f"Expected p-{post_a_id} in tags: {tags_a}"
    assert f"p-{post_b_id}" in tags_b, f"Expected p-{post_b_id} in tags: {tags_b}"

    # Verify tags are distinct - use regex-safe boundary check to avoid
    # false positives when one ID is a prefix of another (e.g., 12 vs 123).
    import re
    tag_a_pattern = rf"(^|,)p-{post_a_id}(,|$)"
    tag_b_pattern = rf"(^|,)p-{post_b_id}(,|$)"
    assert not re.search(tag_b_pattern, tags_a), f"Post B's tag found in A's tags: {tags_a}"
    assert not re.search(tag_a_pattern, tags_b), f"Post A's tag found in B's tags: {tags_b}"

    # Re-warm A right before purge to ensure fresh cache state.
    ra_pre = _head(url_a)
    assert _header(ra_pre, "X-Cache") == "HIT", "Post A should still be cached before purge"

    # Issue a tag-based PURGE that targets only the second post's id tag.
    # The exact=True flag ensures pattern matches complete tags, not substrings.
    _purge_by_tag_pattern(f"p-{post_b_id}", exact=True)

    # After purge:
    # - post B should eventually see a MISS again (its tag was targeted).
    # - post A should remain HIT (it doesn't carry that tag).
    assert_cache_miss(url_b)

    # Check A immediately after B's purge is confirmed - it should still be HIT.
    ra_post = _head(url_a)
    state_a = _header(ra_post, "X-Cache")
    assert state_a == "HIT", f"Unrelated post A should remain HIT after purging B's tag, got {state_a}"

    _enable_tags(False)




