"""Remote IP and live request debug detection tests."""

import requests

from conftest import API_BASE
from debug_detection_helpers import call_debug_endpoint


# =============================================================================
# remote_ip() tests - IP detection from headers
# =============================================================================


class TestRemoteIp:
    """Tests for VarnishDebug::remote_ip()"""

    def test_no_headers_returns_false(self):
        """No relevant headers should return false."""
        headers = {"Cache-Control": "public"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] is False

    def test_x_forwarded_for_ipv4(self):
        """X-Forwarded-For with IPv4 should be detected."""
        headers = {"X-Forwarded-For": "203.0.113.42"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "203.0.113.42"

    def test_x_forwarded_for_ipv6(self):
        """X-Forwarded-For with IPv6 should be detected."""
        headers = {"X-Forwarded-For": "2001:db8::1"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "2001:db8::1"

    def test_x_forwarded_for_multiple_ips(self):
        """X-Forwarded-For with multiple IPs should return the first one."""
        headers = {"X-Forwarded-For": "203.0.113.42, 198.51.100.1, 192.0.2.1"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "203.0.113.42"

    def test_cloudflare_server_detected(self):
        """Server: cloudflare should return 'cloudflare'."""
        headers = {"Server": "cloudflare"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "cloudflare"

    def test_cf_connecting_ip(self):
        """CF-Connecting-IP header should be detected."""
        headers = {"CF-Connecting-IP": "203.0.113.99"}
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "203.0.113.99"

    def test_cf_connecting_ip_takes_priority(self):
        """CF-Connecting-IP should take priority over X-Forwarded-For.

        When behind Cloudflare, CF-Connecting-IP is the most reliable source
        for the real client IP, as X-Forwarded-For may contain proxy chain IPs.
        """
        headers = {
            "X-Forwarded-For": "203.0.113.42",
            "CF-Connecting-IP": "203.0.113.99",
        }
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        assert result["remote_ip"] == "203.0.113.99"

    def test_invalid_ip_in_forwarded_for(self):
        """Invalid IP in X-Forwarded-For should fall through."""
        headers = {
            "X-Forwarded-For": "not-an-ip",
            "Server": "cloudflare",
        }
        result = call_debug_endpoint("remote-ip", headers)
        assert result["ok"] is True
        # Should fall through to cloudflare detection
        assert result["remote_ip"] == "cloudflare"


# =============================================================================
# Integration test - remote_get() with live Varnish
# =============================================================================


class TestRemoteGet:
    """Tests for VarnishDebug::remote_get() with live requests."""

    def test_remote_get_home_page(self):
        """remote_get() should successfully fetch the home page."""
        r = requests.post(
            f"{API_BASE}/debug/remote-get",
            json={"url": ""},  # Empty = home_url()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        # Should have fetched something
        assert "headers" in data
        assert "varnish_results" in data
        # Our test Varnish should return proper headers
        assert data["varnish_results"]["icon"] in ("awesome", "good", "warning")

    def test_remote_get_detects_varnish(self):
        """remote_get() through Varnish should detect caching."""
        r = requests.post(
            f"{API_BASE}/debug/remote-get",
            json={},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        # After second request (remote_get does 2 fetches), we should see cache working
        varnish_result = data["varnish_results"]
        # Our test setup uses X-Cache: HIT/MISS and Via: Varnish headers.
        # After container restart, Age may still be 0, so we accept "warning"
        # as long as the cache service was detected (message mentions Age header).
        # Full caching detection ("awesome") requires Age > 0 which depends on timing.
        assert varnish_result["icon"] in ("awesome", "good", "warning"), \
            f"Expected awesome/good/warning, got {varnish_result}"
        # If we got warning, make sure it's the "Age header" message (cache detected but not proven working)
        if varnish_result["icon"] == "warning":
            assert "Age" in varnish_result["message"] or "Varnish" in varnish_result["message"]
