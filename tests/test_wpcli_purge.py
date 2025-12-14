"""
Tests for WP-CLI purge command functionality.

These tests verify the various purge modes available via WP-CLI:
- Full site purge (default, --all)
- URL purge with wildcard (default when URL provided)
- URL purge without wildcard (--url-only)
- Tag-based purge (--tag)
"""

import time
import requests
from urllib.parse import urlparse, urlunparse

from conftest import API_BASE, WP_URL, _host_headers, fresh_post


def _head(url: str):
    """Make a HEAD request through Varnish."""
    r = requests.head(url, allow_redirects=False, headers=_host_headers())
    r.raise_for_status()
    return r


def _header(resp, name: str) -> str:
    """Get a response header value."""
    return resp.headers.get(name)


def _cli_purge(
    url: str = None,
    all_flag: bool = False,
    url_only: bool = False,
    wildcard: bool = False,
    tag: str = None,
):
    """
    Simulate WP-CLI varnish purge command via REST API.

    This mirrors the logic in wp-cli.php purge() method.
    """
    payload = {
        "subcommand": "purge",
        "url": url,
        "all": all_flag,
        "url_only": url_only,
        "wildcard": wildcard,
        "tag": tag,
    }
    r = requests.post(
        f"{API_BASE}/wp-cli/varnish",
        json=payload,
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


def _enable_tags(enabled: bool):
    """Toggle tag-based purging mode."""
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": bool(enabled)},
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


def _purge_all():
    """Purge entire site cache to reset state."""
    r = requests.post(
        f"{API_BASE}/purge",
        json={"all": True},
        headers=_host_headers(),
    )
    r.raise_for_status()


def test_cli_purge_no_args_flushes_entire_site():
    """
    `wp varnish purge` (no arguments) should send a full site purge request.
    """
    # Run CLI purge with no arguments (simulates `wp varnish purge`).
    result = _cli_purge()
    assert result["ok"] is True
    assert result["type"] == "full"
    assert "?vhp-regex" in result["purge_url"]

    # Verify the correct purge headers were sent.
    assert len(result["captured_headers"]) > 0
    for headers in result["captured_headers"]:
        if "X-Purge-Method" in headers:
            assert headers["X-Purge-Method"] == "regex"


def test_cli_purge_all_flag_flushes_entire_site():
    """
    `wp varnish purge --all` should explicitly send a full site purge request.
    """
    # Run CLI purge with --all flag.
    result = _cli_purge(all_flag=True)
    assert result["ok"] is True
    assert result["type"] == "full"
    assert "?vhp-regex" in result["purge_url"]

    # Verify the correct purge headers were sent.
    assert len(result["captured_headers"]) > 0
    for headers in result["captured_headers"]:
        if "X-Purge-Method" in headers:
            assert headers["X-Purge-Method"] == "regex"


def test_cli_purge_url_uses_wildcard_by_default(fresh_post):
    """
    `wp varnish purge <url>` should purge the URL with wildcard by default.
    """
    post_id, url = fresh_post

    # Run CLI purge with a URL.
    result = _cli_purge(url=url)
    assert result["ok"] is True
    assert result["type"] == "wildcard"
    assert "?vhp-regex" in result["purge_url"]


def test_cli_purge_url_only_purges_exact_url(fresh_post):
    """
    `wp varnish purge <url> --url-only` should purge only the exact URL.
    """
    post_id, url = fresh_post

    # Run CLI purge with --url-only.
    result = _cli_purge(url=url, url_only=True)
    assert result["ok"] is True
    assert result["type"] == "url_only"
    # Should NOT have the regex suffix.
    assert "?vhp-regex" not in result["purge_url"]
    assert result["purge_url"] == url


def test_cli_purge_tag_sends_tag_purge():
    """
    `wp varnish purge --tag=<tag>` should purge by cache tag.
    """
    # Enable tags mode for this test.
    _enable_tags(True)

    try:
        # Run CLI purge with --tag.
        result = _cli_purge(tag="p-12345")
        assert result["ok"] is True
        assert result["type"] == "tag"
        assert result["tag"] == "p-12345"

        # Verify headers were captured.
        assert len(result["captured_headers"]) > 0
        # Check that the tag pattern header was sent.
        found_tag_header = False
        for headers in result["captured_headers"]:
            if "X-Cache-Tags-Pattern" in headers:
                assert "p-12345" in headers["X-Cache-Tags-Pattern"]
                found_tag_header = True
                break
        assert found_tag_header, "Expected X-Cache-Tags-Pattern header"

    finally:
        _enable_tags(False)


def test_cli_purge_tag_with_post_type():
    """
    `wp varnish purge --tag=pt-post` should purge all posts.
    """
    _enable_tags(True)

    try:
        result = _cli_purge(tag="pt-post")
        assert result["ok"] is True
        assert result["type"] == "tag"
        assert result["tag"] == "pt-post"

    finally:
        _enable_tags(False)


def test_cli_purge_tag_with_home():
    """
    `wp varnish purge --tag=home` should purge the home page.
    """
    _enable_tags(True)

    try:
        result = _cli_purge(tag="home")
        assert result["ok"] is True
        assert result["type"] == "tag"
        assert result["tag"] == "home"

    finally:
        _enable_tags(False)


def test_cli_purge_url_only_vs_wildcard_different_behavior(fresh_post):
    """
    Verify --url-only and default (wildcard) produce different PURGE requests.
    """
    post_id, url = fresh_post

    # First, test wildcard mode (default).
    wildcard_result = _cli_purge(url=url)
    assert wildcard_result["type"] == "wildcard"
    wildcard_url = wildcard_result["purge_url"]

    # Then, test url-only mode.
    url_only_result = _cli_purge(url=url, url_only=True)
    assert url_only_result["type"] == "url_only"
    url_only_url = url_only_result["purge_url"]

    # They should be different.
    assert wildcard_url != url_only_url
    assert "?vhp-regex" in wildcard_url
    assert "?vhp-regex" not in url_only_url


def test_cli_purge_headers_contain_x_purge_method(fresh_post):
    """
    Verify PURGE requests include appropriate X-Purge-Method header.
    """
    post_id, url = fresh_post

    # Full purge should use regex method.
    full_result = _cli_purge()
    assert full_result["ok"] is True
    assert len(full_result["captured_headers"]) > 0

    for headers in full_result["captured_headers"]:
        if "X-Purge-Method" in headers:
            assert headers["X-Purge-Method"] == "regex"

    # URL-only purge should use default method.
    url_only_result = _cli_purge(url=url, url_only=True)
    assert url_only_result["ok"] is True

    for headers in url_only_result["captured_headers"]:
        if "X-Purge-Method" in headers:
            assert headers["X-Purge-Method"] == "default"


def test_cli_purge_tag_headers_contain_tags_method():
    """
    Verify tag-based PURGE requests include X-Purge-Method: tags header.
    """
    _enable_tags(True)

    try:
        result = _cli_purge(tag="test-tag")
        assert result["ok"] is True
        assert len(result["captured_headers"]) > 0

        found_tags_method = False
        for headers in result["captured_headers"]:
            if headers.get("X-Purge-Method") == "tags":
                found_tags_method = True
                break

        assert found_tags_method, "Expected X-Purge-Method: tags header"

    finally:
        _enable_tags(False)

