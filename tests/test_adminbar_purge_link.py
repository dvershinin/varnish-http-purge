import os
import time
from urllib.parse import urlparse, urlunparse
import requests
import pytest

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
API_BASE = f"{WP_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def _to_container_url(u: str) -> str:
    orig = urlparse(u)
    dest = urlparse(WP_URL)
    return urlunparse((dest.scheme, dest.netloc, orig.path, orig.params, orig.query, orig.fragment))


def head(url: str):
    r = requests.head(url, headers=_host_headers(), allow_redirects=False)
    r.raise_for_status()
    return r


def header(r, name: str) -> str:
    return r.headers.get(name)


def _purge_all():
    r = requests.post(f"{API_BASE}/purge", json={"all": True}, headers=_host_headers())
    r.raise_for_status()


def _warm_get(url: str):
    # Use GET to ensure cache fill; tolerate redirects
    r = requests.get(url, headers=_host_headers(), allow_redirects=False)
    return r


@pytest.mark.parametrize("mode,expect_miss", [
    ("old", False),
    ("new", True),
])
def test_adminbar_purge_link_no_trailing_slash(mode, expect_miss):
    r = requests.post(f"{API_BASE}/permalinks", json={"structure": "/%postname%"}, headers=_host_headers())
    r.raise_for_status()

    c = requests.post(f"{API_BASE}/post", json={}, headers=_host_headers())
    c.raise_for_status()
    data = c.json()
    url = _to_container_url(data["url"]).rstrip('/')

    _purge_all()
    time.sleep(0.3)
    assert header(head(url), "X-Cache") == "MISS"
    # Actively warm with a GET to avoid HEAD-only warm flakiness
    _warm_get(url)
    # Retry a few times until HIT
    got = None
    for _ in range(6):
        time.sleep(0.25)
        got = header(head(url), "X-Cache")
        if got == "HIT":
            break
    assert got == "HIT"

    # Simulate admin bar purge effect server-side, focusing on the URL building logic
    b = requests.post(f"{API_BASE}/adminbar-purge-exec", json={"page_url": url, "mode": mode}, headers=_host_headers())
    b.raise_for_status()

    hit_miss = None
    for _ in range(10):
        time.sleep(0.4)
        hit_miss = header(head(url), "X-Cache")
        if hit_miss == "MISS":
            break

    if expect_miss:
        assert hit_miss == "MISS"
    else:
        assert hit_miss != "MISS"


