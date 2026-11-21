"""Shared helpers for the XML sitemap purge tests."""

import time
import uuid

import pytest
import requests

from conftest import API_BASE, WP_URL, assert_cache_hit

INDEX = "sitemap_index.xml"


def sitemap(name: str) -> str:
    """Build the public URL of a sitemap file.

    Args:
        name (str): Sitemap file name, e.g. "post-sitemap.xml".

    Returns:
        str: Absolute URL through Varnish.
    """
    return f"{WP_URL}/{name}"


def api(method: str, path: str, **kwargs) -> dict:
    """Call a test-control REST endpoint.

    Args:
        method (str): HTTP method.
        path (str): Path below the test API base, starting with a slash.
        **kwargs: Extra arguments for requests.request.

    Returns:
        dict: Decoded JSON response.

    Raises:
        requests.HTTPError: If the endpoint answers with an error status.
    """
    resp = requests.request(method, f"{API_BASE}{path}", timeout=30, **kwargs)
    resp.raise_for_status()
    return resp.json()


def unique(prefix: str) -> str:
    """Return a name that no earlier test has used.

    Args:
        prefix (str): Readable prefix.

    Returns:
        str: The prefix followed by a random suffix.
    """
    return f"{prefix} {uuid.uuid4().hex[:8]}"


def create_post(**fields) -> dict:
    """Create a post through the test API (published unless told otherwise).

    Args:
        **fields: Post fields accepted by the /post endpoint.

    Returns:
        dict: The endpoint's answer with "id", "url" and "tag_ids".
    """
    fields.setdefault("title", unique("Sitemap post"))
    return api("POST", "/post", json=fields)


def set_status(post_id: int, status: str) -> dict:
    """Change a post's status ("trash" trashes it).

    Args:
        post_id (int): Post ID.
        status (str): Target status.

    Returns:
        dict: The endpoint's answer.
    """
    return api("POST", "/sitemap/post-status", json={"post_id": post_id, "status": status})


def configure(per_page: int = 0, force: str = "") -> None:
    """Set the sitemap test overrides and drop every cached page.

    Args:
        per_page (int): Sitemap page size; 0 restores the default.
        force (str): "on" or "off" to force the feature; "" detects.
    """
    api("POST", "/sitemap/config", json={"per_page": per_page, "force": force})
    api("POST", "/purge", json={"all": True})


def reset_purge_log(enabled: bool = True) -> None:
    """Empty the PURGE log and switch it on or off.

    Args:
        enabled (bool): Whether to keep logging afterwards.
    """
    api("POST", "/purge-log/reset", json={"enabled": enabled})


def all_purges() -> list:
    """Read every PURGE request logged since the last reset.

    Returns:
        list: Log entries with "url" and "method".
    """
    return api("GET", "/purge-log")["log"]


def sitemap_purges() -> set:
    """Read the sitemap URLs purged since the last reset.

    Returns:
        set: Purged URLs whose file name contains "sitemap".
    """
    return {entry["url"] for entry in all_purges() if "sitemap" in entry["url"].rsplit("/", 1)[-1]}


def wait_for_sitemap_purges(expected: set, attempts: int = 20, delay: float = 0.25) -> set:
    """Poll the PURGE log until it holds the expected sitemap URLs.

    Args:
        expected (set): Sitemap URLs that should have been purged.
        attempts (int): Maximum number of polls.
        delay (float): Seconds between polls.

    Returns:
        set: The sitemap URLs found in the log on the last poll.
    """
    found = set()
    for _ in range(attempts):
        found = sitemap_purges()
        if found == expected:
            break
        time.sleep(delay)
    return found


def urls(*names: str) -> set:
    """Build the expected purge URLs for a list of sitemap files.

    Args:
        *names (str): Sitemap file names.

    Returns:
        set: Absolute URLs as the plugin sends them.
    """
    return {sitemap(name) for name in names}


def warm(*names: str) -> None:
    """Fill the cache for the given sitemaps and require a HIT.

    Args:
        *names (str): Sitemap file names.
    """
    for name in names:
        assert_cache_hit(sitemap(name))


def cache_state(name: str) -> str:
    """Read the cache state of a sitemap with one request.

    Args:
        name (str): Sitemap file name.

    Returns:
        str: The X-Cache header value ("HIT" or "MISS").
    """
    return requests.head(sitemap(name), allow_redirects=False, timeout=30).headers.get("X-Cache")


def body(name: str) -> str:
    """Fetch a sitemap.

    Args:
        name (str): Sitemap file name.

    Returns:
        str: Response body.
    """
    resp = requests.get(sitemap(name), timeout=30)
    resp.raise_for_status()
    return resp.text


def set_yoast(active: bool) -> None:
    """Activate or deactivate Yoast SEO and flush rewrite rules and cache.

    Args:
        active (bool): Target state.
    """
    state = api("POST", "/sitemap/yoast", json={"active": active})
    assert state["active"] is active, state
    # A second request, so the rules are rebuilt with the new plugin set loaded.
    api("POST", "/permalinks", json={"structure": "/%postname%/"})
    api("POST", "/purge", json={"all": True})


@pytest.fixture(scope="module")
def yoast():
    """Run a test module with Yoast SEO sitemaps switched on.

    Yields:
        None: Control returns to the tests while Yoast SEO is active.
    """
    set_yoast(True)
    api("POST", "/sitemap/config", json={"per_page": 0, "force": ""})
    api("POST", "/cron-mode", json={"mode": "force_off"})
    api("POST", "/tags-mode", json={"enabled": False})
    for _ in range(40):
        resp = requests.get(sitemap(INDEX), timeout=30)
        if resp.status_code == 200 and "<sitemapindex" in resp.text:
            break
        time.sleep(0.5)
    else:
        pytest.fail(f"Yoast sitemap index never came up: HTTP {resp.status_code}")
    api("POST", "/purge", json={"all": True})
    try:
        yield
    finally:
        api("POST", "/sitemap/config", json={"per_page": 0, "force": ""})
        reset_purge_log(enabled=False)
        set_yoast(False)
