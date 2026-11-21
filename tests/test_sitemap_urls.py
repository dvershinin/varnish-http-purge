"""Sitemap purge URL generation, pagination, switches and purge modes."""

from conftest import API_BASE, assert_cache_miss  # noqa: F401
from sitemap_helpers import (  # noqa: F401
    INDEX,
    all_purges,
    api,
    configure,
    create_post,
    reset_purge_log,
    set_yoast,
    sitemap,
    sitemap_purges,
    unique,
    urls,
    wait_for_sitemap_purges,
    warm,
    yoast,
)

POSTS = "post-sitemap.xml"
CATEGORIES = "category-sitemap.xml"
TAGS = "post_tag-sitemap.xml"
AUTHORS = "author-sitemap.xml"
FULL_PURGE = sitemap(".*")


def _post_urls(post_id: int) -> dict:
    return api("GET", "/sitemap/urls", params={"post_id": post_id})


def test_inactive_without_a_sitemap_plugin():
    # Runs before the yoast fixture of this module switches Yoast SEO on.
    set_yoast(False)
    post = create_post()
    try:
        answer = _post_urls(post["id"])
        assert answer["enabled"] is False
        assert answer["urls"] == []

        reset_purge_log()
        api("PUT", f"/post/{post['id']}", json={"content": unique("No sitemap plugin")})
        assert all_purges(), "the post's own URLs are still purged"
        assert sitemap_purges() == set()
    finally:
        reset_purge_log(enabled=False)


def test_filter_opts_in_a_compatible_sitemap_plugin():
    set_yoast(False)
    post = create_post()
    configure(force="on")
    try:
        answer = _post_urls(post["id"])
        assert answer["enabled"] is True
        assert set(answer["urls"]) == urls(INDEX, POSTS, CATEGORIES)
    finally:
        configure()


def test_urls_for_a_post_are_exact_and_start_with_the_index(yoast):
    post = create_post(tags=[unique("url-tag")])

    answer = _post_urls(post["id"])

    assert answer["enabled"] is True
    assert answer["urls"][0] == sitemap(INDEX)
    assert set(answer["urls"]) == urls(INDEX, POSTS, CATEGORIES, TAGS)
    assert len(answer["urls"]) == len(set(answer["urls"]))


def test_filter_switches_the_feature_off(yoast):
    post = create_post()
    configure(force="off")
    try:
        assert _post_urls(post["id"]) == {"ok": True, "enabled": False, "urls": []}
        reset_purge_log()
        api("PUT", f"/post/{post['id']}", json={"content": unique("Feature off")})
        assert all_purges(), "the post's own URLs are still purged"
        assert sitemap_purges() == set()
    finally:
        configure()


def test_paginated_sitemap_is_purged_with_one_wildcard(yoast):
    first = create_post()
    create_post()
    configure(per_page=1)
    try:
        answer = _post_urls(first["id"])
        assert sitemap("post-sitemap?vhp-regex") in answer["urls"]
        assert sitemap(POSTS) not in answer["urls"]

        warm(INDEX, POSTS, "post-sitemap2.xml")
        reset_purge_log()
        api("PUT", f"/post/{first['id']}", json={"content": unique("Paginated edit")})

        expected = {sitemap(INDEX), sitemap("post-sitemap.*"), sitemap("category-sitemap.*")}
        assert wait_for_sitemap_purges(expected) == expected
        methods = {entry["url"]: entry["method"] for entry in all_purges()}
        assert methods[sitemap("post-sitemap.*")] == "regex"
        assert methods[sitemap(INDEX)] == "default"
        # Every page of the type is gone, not only the first.
        assert_cache_miss(sitemap(POSTS))
        assert_cache_miss(sitemap("post-sitemap2.xml"))
    finally:
        configure()


def test_sitemap_urls_do_not_count_toward_the_full_purge_limit(yoast):
    post = create_post(tags=[unique("limit-tag-a"), unique("limit-tag-b")])
    own_urls = set(api("POST", "/purge", json={"post_id": post["id"]})["generated"])
    # One spare URL: with the four sitemap URLs counted, the batch would cross the limit.
    api("POST", "/option", json={"name": "vhp_varnish_max_posts_before_all", "value": len(own_urls) + 2})
    try:
        reset_purge_log()
        api("PUT", f"/post/{post['id']}", json={"content": unique("Limit edit")})

        expected = urls(INDEX, POSTS, CATEGORIES, TAGS)
        assert wait_for_sitemap_purges(expected) == expected
        assert FULL_PURGE not in {entry["url"] for entry in all_purges()}
    finally:
        api("POST", "/option", json={"name": "vhp_varnish_max_posts_before_all", "value": 50})


def test_cron_mode_queues_sitemap_urls(yoast):
    warm(INDEX, POSTS)
    api("POST", "/cron-mode", json={"mode": "force_on"})
    api("POST", "/purge-queue/clear")
    try:
        reset_purge_log()
        create_post()

        queued = set(api("GET", "/purge-queue")["queue"]["urls"])
        assert urls(INDEX, POSTS, CATEGORIES, AUTHORS) <= queued
        assert sitemap_purges() == set(), "nothing is sent before the queue runs"

        api("POST", "/run-cron-processor")
        assert urls(INDEX, POSTS) <= sitemap_purges()
        assert_cache_miss(sitemap(POSTS))
    finally:
        api("POST", "/cron-mode", json={"mode": "force_off"})
        api("POST", "/purge-queue/clear")
