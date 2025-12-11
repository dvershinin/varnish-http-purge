import time
import requests
from urllib.parse import urlparse, urlunparse

from conftest import API_BASE, WP_URL, _host_headers, get_headers, fresh_post


def _head(url: str):
    r = requests.head(url, allow_redirects=False, headers=_host_headers())
    r.raise_for_status()
    return r


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

    # Warm cache to HIT; tolerate a few initial MISS responses.
    hit_state = None
    for _ in range(6):
        time.sleep(0.25)
        r1 = _head(url)
        hit_state = _header(r1, "X-Cache")
        if hit_state == "HIT":
            break
    assert hit_state == "HIT", f"Expected to observe a HIT after warming under tag mode, got {hit_state}"

    # Update the post to trigger tag-based purge.
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated with tags {time.time()}"},
        headers=_host_headers(),
    )
    rq.raise_for_status()

    # Eventually we should see a MISS again due to tag-based purge.
    hit_miss = None
    for _ in range(12):
        time.sleep(0.5)
        r2 = _head(url)
        hit_miss = _header(r2, "X-Cache")
        if hit_miss == "MISS":
            break
    assert hit_miss == "MISS", "Expected MISS after update when tag-based purging is enabled"

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
        headers=_host_headers(),
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

    # Basic sanity: we should see the post-id tag and at least one taxonomy tag.
    assert f"p-{post_id}" in tags_header
    tag_values = [t for t in tags_header.split(",") if t]
    assert any(t.startswith("t-") for t in tag_values), f"Expected at least one taxonomy tag in header, got: {tags_header}"

    # Warm to HIT.
    time.sleep(0.2)
    r1 = _head(url)
    assert _header(r1, "X-Cache") == "HIT"

    # Update the post to trigger tag-based purge; this will generate batched
    # X-Cache-Tags-Pattern PURGE requests behind the scenes.
    rq = requests.put(
        f"{API_BASE}/post/{post_id}",
        json={"content": f"Updated with many tags {time.time()}"},
        headers=_host_headers(),
    )
    rq.raise_for_status()

    # Eventually we should see a MISS again due to tag-based purge, proving that
    # batching of tag patterns does not prevent correct invalidation.
    hit_miss = None
    for _ in range(12):
        time.sleep(0.5)
        r2 = _head(url)
        hit_miss = _header(r2, "X-Cache")
        if hit_miss == "MISS":
            break
    assert hit_miss == "MISS", "Expected MISS after update when many tags trigger batched purges"

    # Disable tag-based purging again so other tests see legacy behaviour.
    _enable_tags(False)


def _create_post_raw():
    """Helper that mirrors the fresh_post fixture but returns both id and rewritten URL."""
    r = requests.post(f"{API_BASE}/post", json={}, headers=_host_headers())
    r.raise_for_status()
    data = r.json()
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))
    return data["id"], url


def _purge_by_tag_pattern(pattern: str):
    """Send a raw PURGE with a specific X-Cache-Tags-Pattern directly to Varnish.

    This bypasses the plugin's own batching logic and is used as a sanity check
    that the VCL is not over-eager (i.e. a purge for an unrelated tag does not
    evict objects that don't carry that tag).
    """
    headers = _host_headers()
    headers.update(
        {
            "X-Purge-Method": "tags",
            "X-Cache-Tags-Pattern": pattern,
        }
    )
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
    r0a = _head(url_a)
    assert _header(r0a, "X-Cache") == "MISS"
    r1a = _head(url_a)
    assert _header(r1a, "X-Cache") == "HIT"

    r0b = _head(url_b)
    assert _header(r0b, "X-Cache") == "MISS"
    r1b = _head(url_b)
    assert _header(r1b, "X-Cache") == "HIT"

    # Capture tags for each to ensure they differ at least in the post-id tag.
    tags_a = _header(r1a, "X-Cache-Tags") or ""
    tags_b = _header(r1b, "X-Cache-Tags") or ""
    assert f"p-{post_a_id}" in tags_a
    assert f"p-{post_b_id}" in tags_b
    assert f"p-{post_a_id}" not in tags_b
    assert f"p-{post_b_id}" not in tags_a

    # Issue a tag-based PURGE that targets only the second post's id tag.
    _purge_by_tag_pattern(f"p-{post_b_id}")

    # After purge:
    # - post B should eventually see a MISS again (its tag was targeted).
    # - post A should remain HIT (it doesn't carry that tag).
    hit_miss_b = None
    for _ in range(12):
        time.sleep(0.5)
        rb = _head(url_b)
        hit_miss_b = _header(rb, "X-Cache")
        if hit_miss_b == "MISS":
            break
    assert hit_miss_b == "MISS", "Expected MISS on post B after PURGE by its tag"

    # Now poll A for a while and ensure we never observe a MISS. Since we warmed
    # it before issuing a tag-based purge that does not target it, any MISS
    # here would indicate that the purge was over-eager.
    saw_miss_a = False
    for _ in range(12):
        time.sleep(0.5)
        ra = _head(url_a)
        state_a = _header(ra, "X-Cache")
        if state_a == "MISS":
            saw_miss_a = True
            break
    assert not saw_miss_a, "Unrelated post A should never be evicted by PURGE of post B's tag"

    _enable_tags(False)



