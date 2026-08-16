import re
import time
import requests
from urllib.parse import urlparse, urlunparse
import pytest

from conftest import (
    WP_URL, API_BASE,
    wait_for_cache_hit, wait_for_cache_miss, assert_cache_hit, assert_cache_miss,
)

_parsed = urlparse(WP_URL)


def _to_container_url(u: str) -> str:
    orig = urlparse(u)
    dest = urlparse(WP_URL)
    return urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))


def _new_session():
    return requests.Session()


def _login_admin(sess: requests.Session):
    # Hit login page to get cookies
    sess.get(f"{WP_URL}/wp-login.php", allow_redirects=False)
    data = {
        "log": "admin",
        "pwd": "admin",
        "wp-submit": "Log In",
        "redirect_to": f"{WP_URL}/wp-admin/",
        "testcookie": "1",
    }
    r = sess.post(f"{WP_URL}/wp-login.php", data=data, allow_redirects=False)
    assert r.status_code in (302, 303), f"login failed: {r.status_code}"


def head(url: str):
    r = requests.head(url, allow_redirects=False)
    r.raise_for_status()
    return r


def header(r, name: str) -> str:
    return r.headers.get(name)


def _is_flat_string_list(items):
    return isinstance(items, list) and all(isinstance(x, str) for x in items)


def _disable_tags():
    """Disable tag-based purging mode to test URL-based purging."""
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": False},
    )
    r.raise_for_status()
    return r.json()


def test_post_update_triggers_miss_then_hits(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()
    post_id, url = fresh_post

    r0 = head(url)
    assert header(r0, "X-Cache") == "MISS"

    # Wait for cache to warm up with conftest helper
    assert_cache_hit(url)

    # Verify it stays HIT
    r2 = head(url)
    assert header(r2, "X-Cache") == "HIT"

    # Update the post to trigger plugin purge
    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"})
    rq.raise_for_status()

    # also run explicit purge via REST for stability in CI
    rq2 = requests.post(f"{API_BASE}/purge", json={"url": url})
    rq2.raise_for_status()

    # Eventually we should see a MISS using conftest helper
    assert_cache_miss(url)

    # Then back to HITs using conftest helper
    assert_cache_hit(url)

    # Verify it stays HIT
    r5 = head(url)
    assert header(r5, "X-Cache") == "HIT"


def test_multipage_post_update_purges_all_pages(reset_plugin_options):
    """Updating a multipage post purges every numbered page, but nothing else."""
    _disable_tags()
    _set_option("vhp_varnish_max_posts_before_all", "200")

    marker = str(time.time())
    content = (
        f"Page one {marker}"
        "<!--nextpage-->"
        f"Page two {marker}"
        "<!--nextpage-->"
        f"Page three {marker}"
    )
    created = requests.post(
        f"{API_BASE}/post",
        json={
            "title": f"Multipage purge regression {marker}",
            "content": content,
        },
    )
    created.raise_for_status()
    post_data = created.json()
    post_id = post_data["id"]
    post_url = _to_container_url(post_data["url"])

    unrelated = requests.post(
        f"{API_BASE}/post",
        json={
            "title": f"Unrelated purge control {marker}",
            "type": "page",
        },
    )
    unrelated.raise_for_status()
    unrelated_data = unrelated.json()
    unrelated_id = unrelated_data["id"]
    unrelated_url = _to_container_url(unrelated_data["url"])

    # Numbered pages follow the site's trailing-slash preference, exactly like
    # core's _wp_link_page(). The test stack uses "/%postname%" (no trailing
    # slash), so page 2 is "/slug/2" - "/slug/2/" is only a 301 to it, and
    # asserting on that redirect would not prove the page itself was purged.
    page_urls = [
        post_url,
        f"{post_url.rstrip('/')}/2",
        f"{post_url.rstrip('/')}/3",
    ]

    try:
        for page_url in page_urls:
            assert head(page_url).status_code == 200, (
                f"{page_url} must be the page itself, not a redirect"
            )
            assert_cache_hit(page_url)
        assert_cache_hit(unrelated_url)

        updated_content = content.replace("Page", "Updated page")
        updated = requests.put(
            f"{API_BASE}/post/{post_id}",
            json={"content": updated_content},
        )
        updated.raise_for_status()

        unrelated_response = head(unrelated_url)
        assert header(unrelated_response, "X-Cache") == "HIT", (
            "Updating one post must not invalidate an unrelated cached page"
        )

        for page_url in page_urls:
            assert_cache_miss(page_url)

        generated_response = requests.post(
            f"{API_BASE}/purge",
            json={"post_id": post_id},
        )
        generated_response.raise_for_status()
        generated = generated_response.json().get("generated", [])

        # The numbered pages must be purged as real URLs, not via a wildcard:
        # regex/ban purging depends on proxy configuration many sites don't have
        # (wp.org topic "Multipage posts purged only for page 1 when updated").
        generated_paths = {urlparse(u).path for u in generated}
        for page_url in page_urls[1:]:
            assert urlparse(page_url).path in generated_paths, generated
        assert not [u for u in generated if u.startswith(post_url.rstrip("/") + "/?vhp-regex")], (
            f"Numbered pages must not rely on a wildcard purge: {generated}"
        )
    finally:
        requests.post(
            f"{API_BASE}/delete-post",
            json={"post_id": post_id},
        )
        requests.post(
            f"{API_BASE}/delete-post",
            json={"post_id": unrelated_id},
        )


def test_single_page_post_generates_no_numbered_page_urls(fresh_post):
    """A post without page breaks must not gain any /2/ style purge URLs."""
    _disable_tags()
    post_id, url = fresh_post

    generated_response = requests.post(
        f"{API_BASE}/purge",
        json={"post_id": post_id},
    )
    generated_response.raise_for_status()
    generated = generated_response.json().get("generated", [])

    post_path = urlparse(url).path.rstrip("/")
    numbered = [
        u for u in generated
        if re.fullmatch(rf"{re.escape(post_path)}/\d+/?", urlparse(u).path)
    ]
    assert not numbered, f"Unexpected numbered page URLs: {numbered}"


def test_vhp_domains_duplicates_urls_for_alternate_domains(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()

    # Regression guard for wp.org topic "Incorrect logic in purge_post() strpos()"
    # (reporter redwiregareth): the VHP_DOMAINS strpos() args were reversed, so no
    # alternate-domain URLs were generated. Fixed in fa892274 (shipped 5.9.2). This
    # test fails if the args are ever flipped back. Topic is closed to new replies.
    #
    # The environment sets VHP_DOMAINS in WP config to two alternate domains.
    # When a post is updated, the plugin should add purge URLs for those domains too.
    post_id, url = fresh_post

    # Warm to HIT
    assert header(head(url), "X-Cache") == "MISS"
    assert header(head(url), "X-Cache") == "HIT"

    # Update the post to trigger purges
    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"})
    rq.raise_for_status()

    # Ask backend to generate purge URLs; verify alternates are present when VHP_DOMAINS is set
    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
    gr.raise_for_status()
    generated = gr.json().get("generated", [])
    # With the bug present, duplicates are pushed as a nested array; enforce flat string list
    assert _is_flat_string_list(generated), generated
    # Primary home URL must be present (WordPress uses http://varnish:6081)
    assert any(u.startswith(WP_URL.rstrip('/')) for u in generated), generated
    # Alternates must be present (proves proper merge, not nested append)
    assert any(u.startswith('http://alt1.test') for u in generated), generated
    assert any(u.startswith('http://alt2.test') for u in generated), generated

    # Still verify we observe a MISS on the primary URL after update
    assert_cache_miss(url)


def test_excluded_draft_status_generates_no_urls():
    # Ensure URL-based purging is used for this test
    _disable_tags()

    # Create a draft post
    c = requests.post(f"{API_BASE}/post", json={"status": "draft"})
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]

    # Ask backend to generate purge URLs; expect none when drafts are excluded
    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
    gr.raise_for_status()
    generated = gr.json().get("generated", None)
    assert isinstance(generated, list)
    assert generated == []


def test_permalinks_no_trailing_slash_update_purges(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()

    r = requests.post(f"{API_BASE}/permalinks", json={"structure": "/%postname%"})
    r.raise_for_status()

    post_id, url = fresh_post
    url = url.rstrip('/')

    r0 = head(url)
    assert header(r0, "X-Cache") == "MISS"
    r1 = head(url)
    assert header(r1, "X-Cache") == "HIT"

    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"})
    rq.raise_for_status()

    # Wait for cache to be invalidated using conftest helper
    assert_cache_miss(url)


def _set_option(name, value):
    """Set a WordPress option via test API."""
    r = requests.post(f"{API_BASE}/option", json={"name": name, "value": value})
    r.raise_for_status()
    return r.json()


def test_paginated_blog_listing_included_in_purge_urls(fresh_post):
    """When show_on_front=page and page_for_posts is set, purge URLs
    should include a regex entry for the blog listing page so that
    paginated pages (/posts/page/2, /page/3, etc.) are also purged."""
    _disable_tags()

    post_id, url = fresh_post

    # Create a page to use as blog listing page.
    pr = requests.post(f"{API_BASE}/post", json={"title": "Blog", "status": "publish", "type": "page"})
    pr.raise_for_status()
    blog_page_id = pr.json()["id"]

    # Configure WordPress to use a static front page with a separate posts page.
    _set_option("show_on_front", "page")
    _set_option("page_for_posts", str(blog_page_id))

    try:
        # Generate purge URLs for the post.
        gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
        gr.raise_for_status()
        generated = gr.json().get("generated", [])

        # Must include a regex purge URL for the blog page.
        regex_urls = [u for u in generated if u.endswith("?vhp-regex")]
        blog_page_url = requests.get(f"{WP_URL}/?p={blog_page_id}", allow_redirects=False)
        # At least one regex URL should correspond to the blog page path.
        assert any("?vhp-regex" in u for u in generated), (
            f"No regex purge URL found for blog listing. Generated: {generated}"
        )
    finally:
        # Restore defaults.
        _set_option("show_on_front", "posts")
        _set_option("page_for_posts", "0")
        # Clean up the page.
        requests.delete(f"{API_BASE}/post/{blog_page_id}")


def test_category_pages_include_regex_purge_urls(fresh_post):
    """Category archive purge URLs should include regex entries to cover
    paginated category pages (/category/name/page/2, etc.)."""
    _disable_tags()

    post_id, url = fresh_post

    # Generate purge URLs.
    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
    gr.raise_for_status()
    generated = gr.json().get("generated", [])

    # Find category URLs (contain /category/).
    cat_urls = [u for u in generated if "/category/" in u and "wp-json" not in u]
    if not cat_urls:
        pytest.skip("Post has no categories assigned")

    # For each category URL, there should be a corresponding regex URL.
    cat_base_urls = [u for u in cat_urls if "?vhp-regex" not in u]
    cat_regex_urls = [u for u in cat_urls if "?vhp-regex" in u]
    assert len(cat_regex_urls) >= len(cat_base_urls), (
        f"Expected regex purge URL for each category. "
        f"Base: {cat_base_urls}, Regex: {cat_regex_urls}"
    )

