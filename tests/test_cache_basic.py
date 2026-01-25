import requests

from conftest import WP_URL, purge_all_and_wait, wait_for_cache_hit


def header(url: str, name: str) -> str:
    r = requests.head(url, allow_redirects=False)
    r.raise_for_status()
    return r.headers.get(name)


def test_hit_miss_cycle_home():
    home_url = f"{WP_URL}/"

    # Start clean - purge and wait for cache to actually clear
    purge_all_and_wait()

    # First request should be MISS (cache was just cleared)
    h1 = header(home_url, "X-Cache")
    assert h1 == "MISS"

    # Wait for cache to warm up (HIT)
    state = wait_for_cache_hit(home_url)
    assert state == "HIT", f"Expected HIT after warming cache, got {state}"

    # Purge again and wait for cache to clear
    purge_all_and_wait()

    # After purge, should be MISS again
    h3 = header(home_url, "X-Cache")
    assert h3 == "MISS"


