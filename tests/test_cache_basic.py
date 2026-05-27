from conftest import WP_URL, purge_all_and_wait, wait_for_cache_hit, wait_for_cache_miss


def test_hit_miss_cycle_home():
    home_url = f"{WP_URL}/"

    # Start clean - purge and wait for cache to actually clear
    purge_all_and_wait()

    # First request should be MISS (cache was just cleared). Poll instead of a
    # single-shot check: on a cold/loaded stack the purge can take a beat to
    # invalidate the home object, so an immediate read may still catch the old
    # state. (Per the repo testing rule: never single-shot transient cache state.)
    state = wait_for_cache_miss(home_url)
    assert state == "MISS", f"Expected MISS after purge, got {state}"

    # Wait for cache to warm up (HIT)
    state = wait_for_cache_hit(home_url)
    assert state == "HIT", f"Expected HIT after warming cache, got {state}"

    # Purge again and wait for cache to clear
    purge_all_and_wait()

    # After purge, should be MISS again (poll, same reason as above).
    state = wait_for_cache_miss(home_url)
    assert state == "MISS", f"Expected MISS after second purge, got {state}"


