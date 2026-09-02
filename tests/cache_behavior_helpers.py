"""Shared helpers for cache behavior tests."""

import requests
from urllib.parse import urlparse, urlunparse

from conftest import API_BASE, WP_URL, wait_for_cache_hit, wait_for_cache_miss


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
