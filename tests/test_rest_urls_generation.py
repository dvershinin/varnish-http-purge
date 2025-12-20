import os
import requests
import time
from urllib.parse import urlparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
API_BASE = f"{WP_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def _disable_tags():
    """Disable tag-based purging so URL generation is used instead."""
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": False},
        headers=_host_headers(),
    )
    r.raise_for_status()


def _contains(substr: str, urls: list[str]) -> bool:
    return any(isinstance(u, str) and substr in u for u in urls)


def test_generated_urls_include_rest_for_tags_and_categories():
    # Ensure tags mode is disabled so URL generation is used
    _disable_tags()
    time.sleep(0.3)  # Allow setting to propagate
    # Create a post with two tags
    c = requests.post(
        f"{API_BASE}/post",
        json={"title": "Tags Test", "content": "Content", "tags": ["alpha", "beta"]},
        headers=_host_headers(),
    )
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]
    tag_ids = data.get("tag_ids", [])

    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id}, headers=_host_headers())
    gr.raise_for_status()
    generated = gr.json().get("generated", [])

    # Expect REST tag endpoints for each tag id
    for tid in tag_ids:
        assert _contains(f"/wp-json/wp/v2/tags/{tid}/", generated), generated

    # Expect at least one categories REST endpoint
    assert _contains("/wp-json/wp/v2/categories/", generated), generated


def test_generated_urls_include_rest_for_cpt_and_custom_taxonomy():
    # Ensure tags mode is disabled so URL generation is used
    _disable_tags()

    # Register CPT and taxonomy
    r = requests.post(f"{API_BASE}/setup-cpt", headers=_host_headers())
    r.raise_for_status()

    # Create a CPT item with a genre term
    c = requests.post(
        f"{API_BASE}/post",
        json={"title": "Book 1", "content": "Content", "type": "book", "genres": ["scifi"]},
        headers=_host_headers(),
    )
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]
    genre_ids = data.get("genre_ids", [])

    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id}, headers=_host_headers())
    gr.raise_for_status()
    generated = gr.json().get("generated", [])

    # Expect CPT REST endpoint uses custom rest_base 'items'
    assert _contains(f"/wp-json/wp/v2/items/{post_id}/", generated), generated

    # Expect custom taxonomy REST endpoint uses rest_base 'genres' and term IDs
    for gid in genre_ids:
        assert _contains(f"/wp-json/wp/v2/genres/{gid}/", generated), generated


