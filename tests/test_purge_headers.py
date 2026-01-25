import pytest
import requests

from conftest import API_BASE


@pytest.fixture(autouse=True)
def clean_purge_headers(reset_plugin_options):
    """Ensure purge header options are reset before and after each test."""
    pass


def _set_purge_header(name: str | None = None, value: str | None = None) -> dict:
    """
    Helper to configure or reset purge header options via the test MU plugin.

    Passing empty strings (""), or omitting parameters, resets the options.
    """
    payload: dict = {}
    if name is not None:
        payload["name"] = name
    if value is not None:
        payload["value"] = value
    r = requests.post(f"{API_BASE}/purge-header-options", json=payload)
    r.raise_for_status()
    return r.json()


def _get_purge_headers(url: str | None = None) -> dict:
    """
    Ask WordPress (via the MU plugin) to perform a PURGE and report the headers
    that the plugin sent with the request.
    """
    payload: dict = {}
    if url is not None:
        payload["url"] = url
    r = requests.post(f"{API_BASE}/purge-headers", json=payload)
    r.raise_for_status()
    data = r.json()
    return data.get("headers", {})


def test_default_purge_headers_have_no_custom_control_key():
    """
    By default there should be no custom control/auth header; only the core
    headers added by the plugin (host + X-Purge-Method).
    """
    # Options are reset by clean_purge_headers fixture.
    headers = _get_purge_headers()
    assert headers, "Expected to capture some PURGE headers"

    # Sanity: we always send host + X-Purge-Method.
    assert headers.get("host") is not None
    assert headers.get("X-Purge-Method") in {"default", "regex"}

    # With no options set, there should be no extra custom header keys.
    custom_keys = [k for k in headers.keys() if k not in {"host", "X-Purge-Method"}]
    assert custom_keys == []


def test_purge_headers_respect_site_options_when_no_constant():
    """
    When a header name/value is configured via site options and no
    VHP_VARNISH_EXTRA_PURGE_HEADER constant is defined, the plugin should
    include that header on PURGE requests.
    """
    # Configure a simple control key header via the MU helper.
    name = "X-Control-Key"
    value = "TEST_SECRET_VALUE"
    cfg = _set_purge_header(name, value)
    assert cfg["name"] == name
    assert cfg["value"] == value

    headers = _get_purge_headers()
    assert headers.get("X-Control-Key") == value
    # Cleanup handled by clean_purge_headers fixture.


