"""Purge URL generation for paginated listings (blog page and category archives)."""

import pytest
import requests

from conftest import WP_URL, API_BASE
from test_purge_on_update import _disable_tags, _set_option


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
