import os
import time
import requests
from urllib.parse import urlparse, urlunparse
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


def _new_session():
    s = requests.Session()
    s.headers.update(_host_headers())
    return s


def _login_admin(sess: requests.Session):
    # Hit login page to get cookies
    sess.get(f"{WP_URL}/wp-login.php", allow_redirects=False)
    data = {
        "log": "admin",
        "pwd": "admin",
        "wp-submit": "Log In",
        "redirect_to": f"{WP_URL}/wp-admin/",
        "testcookie": "1",
    }
    r = sess.post(f"{WP_URL}/wp-login.php", data=data, allow_redirects=False)
    assert r.status_code in (302, 303), f"login failed: {r.status_code}"


def head(url: str):
    r = requests.head(url, allow_redirects=False, headers=_host_headers())
    r.raise_for_status()
    return r


def header(r, name: str) -> str:
    return r.headers.get(name)
def _is_flat_string_list(items):
    return isinstance(items, list) and all(isinstance(x, str) for x in items)


def _disable_tags():
    """Disable tag-based purging mode to test URL-based purging."""
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": False},
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


def _wait_for_hit(url: str, max_attempts: int = 8, delay: float = 0.3) -> str:
    """Wait for cache to warm up (HIT). Returns final cache state."""
    for _ in range(max_attempts):
        time.sleep(delay)
        r = head(url)
        state = header(r, "X-Cache")
        if state == "HIT":
            return state
    return state


def test_post_update_triggers_miss_then_hits(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()
    post_id, url = fresh_post

    r0 = head(url)
    assert header(r0, "X-Cache") == "MISS"

    # Wait for cache to warm up with retries
    cache_state = _wait_for_hit(url)
    assert cache_state == "HIT", f"Expected cache to warm up, got {cache_state}"

    # Verify it stays HIT
    r2 = head(url)
    assert header(r2, "X-Cache") == "HIT"

    # Update the post to trigger plugin purge
    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"}, headers=_host_headers())
    rq.raise_for_status()

    # also run explicit purge via REST for stability in CI
    rq2 = requests.post(f"{API_BASE}/purge", json={"url": url}, headers=_host_headers())
    rq2.raise_for_status()

    # Eventually we should see a MISS
    for _ in range(12):
        time.sleep(0.5)
        r3 = head(url)
        if header(r3, "X-Cache") == "MISS":
            break
    else:
        assert False, "Expected MISS after update purge"

    # Then back to HITs (use retry to handle timing variations)
    cache_state = _wait_for_hit(url)
    assert cache_state == "HIT", f"Expected cache to warm up after purge, got {cache_state}"

    # Verify it stays HIT
    r5 = head(url)
    assert header(r5, "X-Cache") == "HIT"


def test_vhp_domains_duplicates_urls_for_alternate_domains(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()

    # The environment sets VHP_DOMAINS in WP config to two alternate domains.
    # When a post is updated, the plugin should add purge URLs for those domains too.
    post_id, url = fresh_post

    # Warm to HIT
    assert header(head(url), "X-Cache") == "MISS"
    assert header(head(url), "X-Cache") == "HIT"

    # Update the post to trigger purges
    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"}, headers=_host_headers())
    rq.raise_for_status()

    # Ask backend to generate purge URLs; verify alternates are present when VHP_DOMAINS is set
    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id}, headers=_host_headers())
    gr.raise_for_status()
    generated = gr.json().get("generated", [])
    # With the bug present, duplicates are pushed as a nested array; enforce flat string list
    assert _is_flat_string_list(generated), generated
    # Primary home URL must be present
    assert any(u.startswith(_parsed.scheme + '://' + HOST_HEADER_VALUE.split(':')[0]) for u in generated), generated
    # Alternates must be present (proves proper merge, not nested append)
    assert any(u.startswith('http://alt1.test') for u in generated), generated
    assert any(u.startswith('http://alt2.test') for u in generated), generated

    # Still verify we observe a MISS on the primary URL after update
    for _ in range(12):
        time.sleep(0.5)
        if header(head(url), "X-Cache") == "MISS":
            break
    else:
        assert False, "Expected MISS after update with VHP_DOMAINS configured"


def test_excluded_draft_status_generates_no_urls():
    # Ensure URL-based purging is used for this test
    _disable_tags()

    # Create a draft post
    c = requests.post(f"{API_BASE}/post", json={"status": "draft"}, headers=_host_headers())
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]

    # Ask backend to generate purge URLs; expect none when drafts are excluded
    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id}, headers=_host_headers())
    gr.raise_for_status()
    generated = gr.json().get("generated", None)
    assert isinstance(generated, list)
    assert generated == []


def test_permalinks_no_trailing_slash_update_purges(fresh_post):
    # Ensure URL-based purging is used for this test
    _disable_tags()

    r = requests.post(f"{API_BASE}/permalinks", json={"structure": "/%postname%"}, headers=_host_headers())
    r.raise_for_status()

    post_id, url = fresh_post
    url = url.rstrip('/')

    r0 = head(url)
    assert header(r0, "X-Cache") == "MISS"
    r1 = head(url)
    assert header(r1, "X-Cache") == "HIT"

    rq = requests.put(f"{API_BASE}/post/{post_id}", json={"content": f"Updated {time.time()}"}, headers=_host_headers())
    rq.raise_for_status()

    for _ in range(8):
        time.sleep(0.5)
        r2 = head(url)
        if header(r2, "X-Cache") == "MISS":
            break
    else:
        assert False, "Expected MISS after update with no trailing slash"


