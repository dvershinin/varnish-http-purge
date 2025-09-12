import os
import time
import requests
import pytest
from urllib.parse import urlparse, urlunparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
WP_BACKEND_URL = os.environ.get("WP_BACKEND_URL", "http://wordpress")
API_BASE = f"{WP_BACKEND_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def wait_http_ok(url: str, timeout: float = 60.0, headers=None, accept_codes=None):
    """Wait until an HTTP endpoint responds with one of acceptable status codes.
    Defaults to (200, 301, 302, 403, 503) to be tolerant during warmup behind Varnish.
    """
    if accept_codes is None:
        accept_codes = {200, 301, 302, 403, 503}
    start = time.time()
    while time.time() - start < timeout:
        try:
            r = requests.head(url, timeout=3, allow_redirects=False, headers=headers or {})
            if r.status_code in accept_codes:
                return
        except Exception:
            pass
        time.sleep(1)
    raise RuntimeError(f"Timeout waiting for {url}")


@pytest.fixture(scope="session", autouse=True)
def ensure_up():
    # Ensure WordPress backend is up (Varnish will be hit in tests with retries)
    wait_http_ok(WP_BACKEND_URL, timeout=90.0)


@pytest.fixture()
def fresh_post():
    r = requests.post(f"{API_BASE}/post", json={}, headers=_host_headers())
    r.raise_for_status()
    data = r.json()
    # Rewrite returned absolute URL to use container address (varnish)
    orig = urlparse(data["url"])
    dest = urlparse(WP_URL)
    new_url = urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))
    return data["id"], new_url


def get_headers(url: str):
    r = requests.head(url, allow_redirects=False, headers=_host_headers())
    r.raise_for_status()
    return {k.title(): v for k, v in r.headers.items()}


