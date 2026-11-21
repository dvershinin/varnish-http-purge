"""XML sitemaps are purged when the content they list changes (Yoast SEO)."""

from conftest import API_BASE, assert_cache_miss  # noqa: F401
from sitemap_helpers import (  # noqa: F401
    INDEX,
    api,
    body,
    cache_state,
    create_post,
    reset_purge_log,
    set_status,
    sitemap,
    sitemap_purges,
    unique,
    urls,
    wait_for_sitemap_purges,
    warm,
    yoast,
)

POSTS = "post-sitemap.xml"
PAGES = "page-sitemap.xml"
CATEGORIES = "category-sitemap.xml"
TAGS = "post_tag-sitemap.xml"
AUTHORS = "author-sitemap.xml"


def test_publish_purges_index_and_post_type_sitemap(yoast):
    warm(INDEX, POSTS, PAGES)
    reset_purge_log()

    post = create_post()

    expected = urls(INDEX, POSTS, CATEGORIES, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected
    assert_cache_miss(sitemap(INDEX))
    assert_cache_miss(sitemap(POSTS))
    assert post["url"] in body(POSTS)
    # A sitemap of another post type is left alone.
    assert cache_state(PAGES) == "HIT"


def test_draft_changes_purge_nothing(yoast):
    warm(INDEX, POSTS)
    reset_purge_log()

    draft = create_post(status="draft")
    api("PUT", f"/post/{draft['id']}", json={"content": unique("Draft edit")})

    assert sitemap_purges() == set()
    assert cache_state(INDEX) == "HIT"
    assert cache_state(POSTS) == "HIT"


def test_draft_with_a_new_tag_purges_nothing(yoast):
    warm(INDEX, TAGS)
    reset_purge_log()

    create_post(status="draft", tags=[unique("draft-tag")])

    # The new term has no published post, so no sitemap lists it.
    assert sitemap_purges() == set()
    assert cache_state(TAGS) == "HIT"


def test_update_of_published_post_purges_its_sitemaps(yoast):
    post = create_post()
    warm(INDEX, POSTS)
    reset_purge_log()

    api("PUT", f"/post/{post['id']}", json={"content": unique("Published edit")})

    # No status change, so the author sitemap keeps its entry and is not purged.
    expected = urls(INDEX, POSTS, CATEGORIES)
    assert wait_for_sitemap_purges(expected) == expected
    assert_cache_miss(sitemap(POSTS))


def test_unpublish_removes_post_from_sitemap(yoast):
    post = create_post()
    assert post["url"] in body(POSTS)
    warm(INDEX, POSTS)
    reset_purge_log()

    set_status(post["id"], "draft")

    expected = urls(INDEX, POSTS, CATEGORIES, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected
    assert post["url"] not in body(POSTS)


def test_trash_removes_post_from_sitemap(yoast):
    post = create_post()
    assert post["url"] in body(POSTS)
    warm(INDEX, POSTS)
    reset_purge_log()

    set_status(post["id"], "trash")

    expected = urls(INDEX, POSTS, CATEGORIES, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected
    assert post["url"] not in body(POSTS)


def test_permanent_delete_of_published_post_purges(yoast):
    post = create_post(tags=[unique("deleted-tag")])
    warm(INDEX, POSTS)
    reset_purge_log()

    api("POST", "/delete-post", json={"post_id": post["id"]})

    # The post is gone at flush time; its taxonomies were captured beforehand.
    expected = urls(INDEX, POSTS, CATEGORIES, TAGS, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected
    assert post["url"] not in body(POSTS)


def test_tagged_post_purges_tag_sitemap(yoast):
    reset_purge_log()

    create_post(tags=[unique("fresh-tag")])

    expected = urls(INDEX, POSTS, CATEGORIES, TAGS, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected


def test_term_rename_purges_only_its_taxonomy_sitemap(yoast):
    post = create_post(tags=[unique("renamed-tag")])
    warm(INDEX, POSTS, TAGS)
    reset_purge_log()

    renamed = unique("Renamed")
    api("POST", "/sitemap/term", json={"term_id": post["tag_ids"][0], "taxonomy": "post_tag", "name": renamed})

    expected = urls(INDEX, TAGS)
    assert wait_for_sitemap_purges(expected) == expected
    assert_cache_miss(sitemap(TAGS))
    assert cache_state(POSTS) == "HIT"


def test_user_update_purges_author_sitemap(yoast):
    warm(INDEX, AUTHORS, POSTS)
    reset_purge_log()

    api("POST", "/sitemap/user", json={"description": unique("Bio")})

    expected = urls(INDEX, AUTHORS)
    assert wait_for_sitemap_purges(expected) == expected
    assert_cache_miss(sitemap(AUTHORS))
    assert cache_state(POSTS) == "HIT"


def test_purge_all_sitemaps(yoast):
    warm(INDEX, POSTS, PAGES)

    purged = set(api("POST", "/sitemap/purge-all")["urls"])

    assert urls(INDEX, POSTS, PAGES, CATEGORIES, AUTHORS) <= purged
    # Yoast SEO keeps post formats and (by default) attachments out of the sitemap.
    assert not any("post_format" in url or "attachment" in url for url in purged)
    for name in (INDEX, POSTS, PAGES):
        assert_cache_miss(sitemap(name))


def test_cache_tags_mode_still_purges_sitemaps(yoast):
    # Sitemaps carry no cache tags, so they need their URL purge in tags mode too.
    api("POST", "/tags-mode", json={"enabled": True})
    try:
        warm(INDEX, POSTS)
        reset_purge_log()

        create_post()

        expected = urls(INDEX, POSTS, CATEGORIES, AUTHORS)
        assert wait_for_sitemap_purges(expected) == expected
        assert_cache_miss(sitemap(POSTS))
    finally:
        api("POST", "/tags-mode", json={"enabled": False})


def test_yoast_internal_state_save_purges_nothing(yoast):
    warm(INDEX, POSTS)
    reset_purge_log()

    # Yoast SEO stores indexing progress in the same option as its settings.
    api("POST", "/sitemap/yoast-option", json={"key": "indexing_started", "value": 1794800000})

    assert sitemap_purges() == set()
    assert cache_state(INDEX) == "HIT"


def test_yoast_sitemap_setting_purges_every_sitemap(yoast):
    create_post(tags=[unique("setting-tag")])
    warm(INDEX, POSTS, PAGES, TAGS)
    reset_purge_log()
    try:
        api("POST", "/sitemap/yoast-option", json={"key": "noindex-tax-post_tag", "value": True})

        # Tags just left the sitemap; every other sitemap and the index are purged.
        expected = urls(INDEX, POSTS, PAGES, CATEGORIES, AUTHORS)
        assert expected <= wait_for_sitemap_purges(expected)
        assert "post_tag-sitemap.xml" not in body(INDEX)
    finally:
        api("POST", "/sitemap/yoast-option", json={"key": "noindex-tax-post_tag", "value": False})
