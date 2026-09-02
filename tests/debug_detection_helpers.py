"""Shared request helper for debug detection tests."""

import requests

from conftest import API_BASE


def call_debug_endpoint(endpoint: str, headers: dict) -> dict:
    """Call a VarnishDebug test endpoint with the given headers."""
    r = requests.post(
        f"{API_BASE}/debug/{endpoint}",
        json={"headers": headers},
    )
    r.raise_for_status()
    return r.json()
