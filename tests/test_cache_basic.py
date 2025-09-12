import os
import time
import requests
from urllib.parse import urlparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
API_BASE = f"{WP_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def header(url: str, name: str) -> str:
    r = requests.head(url, allow_redirects=False, headers=_host_headers())
    r.raise_for_status()
    return r.headers.get(name)


def test_hit_miss_cycle_home():
    # Start clean via REST helper
    rq = requests.post(f"{API_BASE}/purge", json={"all": True}, headers=_host_headers())
    rq.raise_for_status()
    time.sleep(0.5)

    h1 = header(f"{WP_URL}/", "X-Cache")
    assert h1 == "MISS"

    h2 = header(f"{WP_URL}/", "X-Cache")
    assert h2 == "HIT"

    # purge all via REST helper
    rq = requests.post(f"{API_BASE}/purge", json={"all": True}, headers=_host_headers())
    rq.raise_for_status()
    time.sleep(0.5)

    h3 = header(f"{WP_URL}/", "X-Cache")
    assert h3 == "MISS"


