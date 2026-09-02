import os
import requests
import pytest
from urllib.parse import urlparse, urlunparse

from cache_helpers import *  # noqa: F401,F403
from cache_helpers import (
    assert_cache_hit,
    assert_cache_miss,
    assert_is_wordpress_response,
    get_headers,
    purge_all_and_wait,
    wait_for_cache_hit,
    wait_for_cache_miss,
    wait_for_header,
    wait_for_option_effect,
    wait_http_ok,
)

# All requests go through Varnish at http://varnish:6081 (internal Docker URL).
# WordPress is installed with this same URL, so no Host header tricks needed.
# The test mu-plugin sends Cache-Control: no-cache headers so API responses are never cached.
WP_URL = os.environ.get("WP_URL", "http://varnish:6081")
# WP_BACKEND_URL is kept for backwards compatibility but points to the same place.
WP_BACKEND_URL = WP_URL
API_BASE = f"{WP_URL}/wp-json/test/v1"

# Standard polling constants for cache state helpers
DEFAULT_POLL_DELAY = 0.25      # seconds between polls
DEFAULT_POLL_ATTEMPTS = 20     # 20 * 0.25 = 5 seconds max


@pytest.fixture(scope="session", autouse=True)
def ensure_up():
    # Allow fast-running or sandboxed environments to skip the long warmup checks
    # by setting VHP_SKIP_ENSURE_UP=1. Locally and in CI this should normally
    # be left unset so we still verify that WordPress/Varnish are reachable.
    if os.environ.get("VHP_SKIP_ENSURE_UP") == "1":
        return

    # Ensure WordPress backend is up and Varnish (public URL) responds, too
    # Be tolerant to redirects/403/503 during warmup
    try:
        wait_http_ok(WP_BACKEND_URL, timeout=120.0)
    except Exception:
        # In environments without a distinct backend, WP_BACKEND_URL may be same as WP_URL
        pass
    try:
        wait_http_ok(WP_URL, timeout=120.0)
    except Exception:
        # Allow tests to proceed; individual tests include retries
        pass

    # By default, run the suite with cron-based purging forced off so behaviour
    # matches the historical synchronous semantics. Individual tests that need
    # cron-mode can toggle it explicitly via the helper endpoint.
    try:
        resp = requests.post(
            f"{API_BASE}/cron-mode",
            json={"mode": "force_off"},
            timeout=5,
        )
        resp.raise_for_status()
    except Exception:
        # If the helper endpoint is unavailable for any reason, continue;
        # tests that rely on it will fail with clearer errors.
        pass


@pytest.fixture()
def fresh_post():
    r = requests.post(f"{API_BASE}/post", json={})
    r.raise_for_status()
    data = r.json()
    # WordPress URL already matches WP_URL (http://varnish:6081), but ensure consistency
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    new_url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))
    return data["id"], new_url


@pytest.fixture()
def reset_plugin_options():
    """
    Reset all VHP plugin options to known defaults before and after test.

    This fixture ensures test isolation by:
    - Resetting options to defaults before the test runs
    - Resetting again after the test completes (cleanup)

    Default state:
    - vhp_varnish_ip: '' (empty)
    - vhp_varnish_devmode: {'active': False, 'expire': 0}
    - vhp_varnish_max_posts_before_all: 50
    - vhp_varnish_use_tags: 0 (disabled)
    - vhp_varnish_force_cron_mode: 'off'
    - No custom purge headers configured
    - No pending purge queue
    """
    def _reset():
        resp = requests.post(f"{API_BASE}/reset-options", json={}, timeout=10)
        resp.raise_for_status()
        return resp.json()

    # Reset before test
    _reset()
    yield _reset
    # Reset after test (cleanup)
    _reset()
