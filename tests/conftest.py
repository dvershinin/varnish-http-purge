import os
import time
import requests
import pytest
from urllib.parse import urlparse, urlunparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
API_BASE = f"{WP_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def wait_http_ok(url: str, timeout: float = 60.0):
    start = time.time()
    while time.time() - start < timeout:
        try:
            r = requests.head(url, timeout=3, allow_redirects=False, headers=_host_headers())
            if r.status_code in (200, 301):
                return
        except Exception:
            pass
        time.sleep(1)
    raise RuntimeError(f"Timeout waiting for {url}")


@pytest.fixture(scope="session", autouse=True)
def ensure_up():
    wait_http_ok(WP_URL)


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


