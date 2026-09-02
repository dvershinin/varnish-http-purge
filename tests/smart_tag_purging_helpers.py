"""Shared helpers for smart tag purging tests."""

import time
import requests
from urllib.parse import urlparse, urlunparse

from conftest import API_BASE, WP_URL


def _head(url: str):
    """Make a HEAD request through Varnish."""
    r = requests.head(url, allow_redirects=False)
    r.raise_for_status()
    return r


def _get(url: str):
    """Make a GET request through Varnish."""
    r = requests.get(url, allow_redirects=False)
    r.raise_for_status()
    return r


def _header(resp, name: str) -> str:
    """Extract a specific header from a response."""
    return resp.headers.get(name)


def _enable_tags(enabled: bool, timeout: float = 10.0):
    """Enable or disable tag-based purging mode and wait for effect."""
    from conftest import wait_for_option_effect

    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": bool(enabled)},
    )
    r.raise_for_status()
    result = r.json()

    wait_for_option_effect(
        url=WP_URL + "/",
        header_name="X-Cache-Tags",
        expected_present=enabled,
        timeout=timeout,
    )
    return result


def _create_post(title: str = None, content: str = None):
    """Create a new post and return (id, url)."""
    payload = {}
    if title:
        payload["title"] = title
    if content:
        payload["content"] = content

    r = requests.post(f"{API_BASE}/post", json=payload)
    r.raise_for_status()
    data = r.json()

    # Rewrite URL to use Varnish container address
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    url = urlunparse(
        (dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment)
    )
    return data["id"], url


def _update_post(post_id: int, content: str = None):
    """Update an existing post."""
    payload = {"content": content or f"Updated at {time.time()}"}
    r = requests.put(
        f"{API_BASE}/post/{post_id}",
        json=payload,
    )
    r.raise_for_status()
    return r.json()


def _purge_all():
    """Purge entire cache."""
    r = requests.post(
        f"{API_BASE}/purge",
        json={"all": True},
    )
    r.raise_for_status()


def _get_blog_url():
    """Get the blog/posts page URL (assumes default WordPress setup)."""
    # In default WordPress setup, the home URL is the blog page
    return WP_URL + "/"
