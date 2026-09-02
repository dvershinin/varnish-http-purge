"""Cache-Purge-Result contract against a real nginx-module-cache-purge arm.

The ``nginx`` service builds ngx_cache_purge from its pinned tag and fronts the
same WordPress backend as Varnish. These tests prove that the plugin does not
infer success from HTTP 200: a tag purge is *confirmed* only when the endpoint
reports ``Cache-Purge-Result: tags``, degrades to a recorded *mismatch* when
the endpoint performed a different operation, and is *failed* when the PURGE
is rejected.
"""

import os
import time
from urllib.parse import urlparse, urlunparse

import pytest
import requests

from conftest import API_BASE, WP_URL

NGINX_URL = os.environ.get("NGINX_URL", "http://nginx:6082")
NGINX_NOTAGS_URL = os.environ.get("NGINX_NOTAGS_URL", "http://nginx:6083")
NGINX_RESTRICTED_URL = os.environ.get("NGINX_RESTRICTED_URL", "http://nginx:6084")

TAG_PURGE_HEADERS = {"X-Purge-Method": "tags"}


def _through(base: str, url: str) -> str:
    """Rewrite a WordPress URL so it is fetched through the given nginx arm."""
    src = urlparse(url)
    dst = urlparse(base)
    return urlunparse((dst.scheme, dst.netloc, src.path, src.params, src.query, src.fragment))


def _get(url: str) -> requests.Response:
    return requests.get(url, allow_redirects=False, timeout=10)


def _wait_cache_status(url: str, expected: str, attempts: int = 40, delay: float = 0.25) -> requests.Response:
    last = None
    for _ in range(attempts):
        r = _get(url)
        last = r.headers.get("X-Cache-Status")
        if last == expected:
            return r
        time.sleep(delay)
    raise AssertionError(f"{url}: expected X-Cache-Status {expected}, last seen {last}")


def _warm(url: str) -> requests.Response:
    """Fetch until nginx serves the URL from its cache."""
    _get(url)
    return _wait_cache_status(url, "HIT")


def _set_tags_mode(enabled: bool, attempts: int = 40, delay: float = 0.25) -> None:
    r = requests.post(f"{API_BASE}/tags-mode", json={"enabled": bool(enabled)}, timeout=10)
    r.raise_for_status()
    # Poll the origin through nginx with unique query strings so each probe
    # is a cache MISS and reflects the current option value.
    for i in range(attempts):
        probe = _get(f"{NGINX_URL}/?vhp_probe={i}-{time.time()}")
        if ("X-Cache-Tags" in probe.headers) == bool(enabled):
            return
        time.sleep(delay)
    raise AssertionError(f"tags mode {enabled} not visible through nginx")


def _set_purge_target(host: str) -> None:
    r = requests.post(f"{API_BASE}/purge-target", json={"host": host}, timeout=10)
    r.raise_for_status()


def _purge_results() -> dict:
    r = requests.get(f"{API_BASE}/purge-results", timeout=10)
    r.raise_for_status()
    return r.json()["results"]


def _wait_purge_result(expected: str, state: str, host: str, attempts: int = 20, delay: float = 0.25) -> dict:
    """Records are keyed by requested operation and purge target host."""
    last = None
    for _ in range(attempts):
        last = _purge_results().get(f"{expected}|{host}")
        if last and last.get("state") == state:
            return last
        time.sleep(delay)
    raise AssertionError(f"no {expected} purge result in state {state}; last={last}")


def _debug_rows() -> dict:
    r = requests.get(f"{API_BASE}/debug/purge-results", timeout=10)
    r.raise_for_status()
    return r.json()["results"]


def _create_post() -> dict:
    r = requests.post(f"{API_BASE}/post", json={"title": f"control {time.time()}"}, timeout=20)
    r.raise_for_status()
    return r.json()


def _update_post(post_id: int) -> None:
    r = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"nginx purge {time.time()}"}, timeout=20)
    r.raise_for_status()


@pytest.fixture()
def nginx_arm(reset_plugin_options):
    """Clean plugin state plus guaranteed teardown of the nginx purge target."""
    yield
    _set_purge_target("")
    requests.post(f"{API_BASE}/tags-mode", json={"enabled": False}, timeout=10)


@pytest.mark.parametrize(
    "value, expected",
    [
        ("exact; count=1", {"operation": "exact", "count": 1}),
        ("tags;count=0", {"operation": "tags", "count": 0}),
        ("wildcard; count=12; future=abc", {"operation": "wildcard", "count": 12}),
        ("all", {"operation": "all", "count": None}),
        ("Tags; Count=3", {"operation": "tags", "count": 3}),
        ("", None),
        ("not a structured field", None),
        (";count=1", None),
    ],
)
def test_parse_cache_purge_result_values(value, expected):
    r = requests.post(f"{API_BASE}/parse-purge-result", json={"value": value}, timeout=10)
    r.raise_for_status()
    parsed = r.json()["parsed"]
    if expected is None:
        assert parsed is None
        return
    assert parsed["operation"] == expected["operation"]
    assert parsed["count"] == expected["count"]
    if "future" in value:
        assert parsed["params"]["future"] == "abc", "unknown parameters must be kept, not rejected"


@pytest.mark.parametrize(
    "payload, state, operation, http_code",
    [
        ({"code": 200, "header": "tags; count=2", "expected": "tags"}, "confirmed", "tags", 200),
        ({"code": 200, "header": "exact; count=1", "expected": "tags"}, "mismatch", "exact", 200),
        ({"code": 200, "header": "", "expected": "tags"}, "unreported", None, 200),
        ({"code": 412, "header": "", "expected": "exact"}, "miss", None, 412),
        ({"code": 403, "header": "", "expected": "tags"}, "failed", None, 403),
        ({"code": 0, "transport": "wp_error", "expected": "tags"}, "failed", None, None),
    ],
)
def test_assess_purge_response_states(payload, state, operation, http_code):
    r = requests.post(f"{API_BASE}/assess-purge-response", json=payload, timeout=10)
    r.raise_for_status()
    assessment = r.json()["assessment"]
    assert assessment["state"] == state
    assert assessment["operation"] == operation
    assert assessment["http_code"] == http_code
    assert assessment["expected"] == payload["expected"]
    if payload.get("transport") == "wp_error":
        assert "connection refused" in assessment["error"]


def test_nginx_tag_purge_reports_result_and_spares_control(nginx_arm, fresh_post):
    _set_tags_mode(True)
    post_id, url = fresh_post
    post_url = _through(NGINX_URL, url)
    home_url = f"{NGINX_URL}/"

    post_hit = _warm(post_url)
    assert f"p-{post_id}" in post_hit.headers.get("X-Cache-Tags", "")

    # Control: a second post whose tags do not carry the purged post id. The
    # home page is no control, it lists the new post and rightly shares its tag.
    control_url = _through(NGINX_URL, _create_post()["url"])
    control_hit = _warm(control_url)
    assert f"p-{post_id}" not in control_hit.headers.get("X-Cache-Tags", "").split(",")

    r = requests.request(
        "PURGE",
        home_url,
        headers={**TAG_PURGE_HEADERS, "X-Cache-Tags-Pattern": rf"\bp-{post_id}\b"},
        timeout=10,
    )
    assert r.status_code == 200, r.text
    result = r.headers.get("Cache-Purge-Result", "")
    assert result.startswith("tags;"), result
    count = int(result.split("count=")[1].split(";")[0])
    assert count >= 1

    _wait_cache_status(post_url, "MISS")
    assert _get(control_url).headers.get("X-Cache-Status") == "HIT", "unrelated object must survive a tag purge"


def test_plugin_tag_purge_is_confirmed_by_nginx(nginx_arm, fresh_post):
    _set_purge_target("nginx:6082")
    _set_tags_mode(True)
    post_id, url = fresh_post
    post_url = _through(NGINX_URL, url)
    _warm(post_url)

    _update_post(post_id)

    recorded = _wait_purge_result("tags", "confirmed", "nginx:6082")
    assert recorded["operation"] == "tags"
    assert recorded["http_code"] == 200
    # The plugin sends one PURGE per pattern batch and the record keeps the
    # last one; a no-match batch legitimately reports "tags; count=0".
    assert isinstance(recorded["count"], int) and recorded["count"] >= 0
    assert recorded["url"].startswith("http://nginx:6082/")

    _wait_cache_status(post_url, "MISS")

    rows = _debug_rows()
    assert rows["Purge Result: tags (nginx:6082)"]["icon"] == "good"
    assert "confirmed" in rows["Purge Result: tags (nginx:6082)"]["message"]


def test_plugin_flags_tag_purge_mismatch_when_nginx_lacks_cache_purge_tags(nginx_arm, fresh_post):
    _set_purge_target("nginx:6083")
    _set_tags_mode(True)
    post_id, url = fresh_post
    post_url = _through(NGINX_NOTAGS_URL, url)
    home_url = f"{NGINX_NOTAGS_URL}/"
    _warm(home_url)
    _warm(post_url)

    # Drive the plugin's tag purge alone: a post update also sends URL purges
    # first, which would remove "/" before the tag PURGE reaches it.
    r = requests.post(f"{API_BASE}/purge-tags", json={"tags": [f"p-{post_id}"]}, timeout=20)
    r.raise_for_status()

    recorded = _wait_purge_result("tags", "mismatch", "nginx:6083")
    assert recorded["operation"] == "exact"
    assert recorded["http_code"] == 200
    # The exact purge removed "/" only; the tagged post was never invalidated.
    assert _get(post_url).headers.get("X-Cache-Status") == "HIT"

    rows = _debug_rows()
    row = rows["Purge Result: tags (nginx:6083)"]
    assert row["icon"] == "bad"
    assert "cache_purge_tags" in row["message"]

    health = requests.post(
        f"{API_BASE}/health-check-debug-log",
        json={"debug_log": {WP_URL: rows}},
        timeout=10,
    )
    health.raise_for_status()
    assert health.json()["result_status"] != "good"


def test_restricted_nginx_rejects_purge(nginx_arm, fresh_post):
    r = requests.request(
        "PURGE",
        f"{NGINX_RESTRICTED_URL}/",
        headers={**TAG_PURGE_HEADERS, "X-Cache-Tags-Pattern": "p-1"},
        timeout=10,
    )
    assert r.status_code == 403
    assert "Cache-Purge-Result" not in r.headers

    _set_purge_target("nginx:6084")
    _set_tags_mode(True)
    post_id, _ = fresh_post
    _update_post(post_id)

    recorded = _wait_purge_result("tags", "failed", "nginx:6084")
    assert recorded["http_code"] == 403
    assert _debug_rows()["Purge Result: tags (nginx:6084)"]["icon"] == "bad"
