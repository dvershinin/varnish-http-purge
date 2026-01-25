import requests

from conftest import API_BASE


def _disable_tags():
    """Disable tag-based purging so URL generation is used instead."""
    r = requests.post(
        f"{API_BASE}/tags-mode",
        json={"enabled": False},
    )
    r.raise_for_status()


def _contains(substr: str, urls: list[str]) -> bool:
    return any(isinstance(u, str) and substr in u for u in urls)


def test_generated_urls_include_rest_for_tags_and_categories():
    # Ensure tags mode is disabled so URL generation is used
    # (option update is synchronous, no sleep needed)
    _disable_tags()
    # Create a post with two tags
    c = requests.post(
        f"{API_BASE}/post",
        json={"title": "Tags Test", "content": "Content", "tags": ["alpha", "beta"]},
    )
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]
    tag_ids = data.get("tag_ids", [])

    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
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

    # Create a CPT item with a genre term (CPT 'book' and taxonomy 'genre' are registered in mu-plugin init)
    c = requests.post(
        f"{API_BASE}/post",
        json={"title": "Book 1", "content": "Content", "type": "book", "genres": ["scifi"]},
    )
    c.raise_for_status()
    data = c.json()
    post_id = data["id"]
    genre_ids = data.get("genre_ids", [])

    gr = requests.post(f"{API_BASE}/purge", json={"post_id": post_id})
    gr.raise_for_status()
    generated = gr.json().get("generated", [])

    # Expect CPT REST endpoint uses custom rest_base 'items'
    assert _contains(f"/wp-json/wp/v2/items/{post_id}/", generated), generated

    # Expect custom taxonomy REST endpoint uses rest_base 'genres' and term IDs
    for gid in genre_ids:
        assert _contains(f"/wp-json/wp/v2/genres/{gid}/", generated), generated


