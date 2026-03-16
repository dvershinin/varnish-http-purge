import requests

from conftest import WP_URL, API_BASE, wait_for_cache_hit


def test_health_score_returns_valid_structure():
    """Health score endpoint returns score, hits, total, and results fields."""
    resp = requests.get(f"{API_BASE}/health-score", timeout=30)
    resp.raise_for_status()
    data = resp.json()

    assert "score" in data, f"Missing 'score' in response: {data}"
    assert "hits" in data, f"Missing 'hits' in response: {data}"
    assert "total" in data, f"Missing 'total' in response: {data}"
    assert "results" in data, f"Missing 'results' in response: {data}"

    assert isinstance(data["score"], int)
    assert 0 <= data["score"] <= 100, f"Score {data['score']} out of range 0-100"
    assert data["total"] > 0, "Expected at least one URL tested"
    assert isinstance(data["results"], list)
    assert len(data["results"]) > 0


def test_health_score_detects_cache_hits():
    """After warming cache, health score should detect at least one HIT."""
    home_url = f"{WP_URL}/"

    # Warm the home page cache first.
    requests.get(home_url, timeout=5)
    wait_for_cache_hit(home_url)

    resp = requests.get(f"{API_BASE}/health-score", timeout=30)
    resp.raise_for_status()
    data = resp.json()

    assert data["hits"] > 0, (
        f"Expected at least one cache HIT after warming, got 0. "
        f"Results: {data['results']}"
    )
    assert data["score"] > 0, f"Expected score > 0, got {data['score']}"
