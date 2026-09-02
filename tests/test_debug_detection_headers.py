"""Compression, Vary, and server debug detection tests."""

from debug_detection_helpers import call_debug_endpoint


# =============================================================================
# gzip_results() tests
# =============================================================================


class TestGzipResults:
    """Tests for VarnishDebug::gzip_results()"""

    def test_no_encoding_returns_empty(self):
        """No Content-Encoding should return empty array."""
        headers = {"Cache-Control": "public"}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        # Result should be empty array when no compression detected
        assert result["result"] is None or result["result"] is False or result["result"] == []

    def test_gzip_content_encoding_detected(self):
        """Content-Encoding: gzip should be detected."""
        headers = {"Content-Encoding": "gzip"}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert result["result"]["icon"] == "good"
        assert "compressing" in result["result"]["message"]

    def test_vary_gzip_detected(self):
        """Vary header with gzip should be detected."""
        headers = {"Vary": "Accept-Encoding, gzip"}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert result["result"]["icon"] == "good"

    def test_fastly_detected(self):
        """Fastly in X-Served-By or Via header should be detected."""
        # Fastly uses X-Served-By with cache- prefix, not Content-Encoding.
        headers = {"X-Served-By": "cache-lax17623-LAX"}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert "Fastly" in result["result"]["message"]

    def test_fastly_detected_via_header(self):
        """Fastly can also be detected via Via header."""
        headers = {"Via": "1.1 varnish, 1.1 fastly"}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert "Fastly" in result["result"]["message"]

    def test_vary_header_as_array(self):
        """Vary header as array should be handled without error.

        This can happen when the server sends multiple Vary headers:
        Vary: Accept-Encoding
        Vary: accept, content-type

        wp_remote_get() collects these as an array.
        """
        headers = {"Vary": ["Accept-Encoding", "accept, content-type"]}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        # Should not crash - result may be empty or have gzip detection

    def test_content_encoding_as_array(self):
        """Content-Encoding as array should be handled."""
        headers = {"Content-Encoding": ["gzip", "chunked"]}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert result["result"]["icon"] == "good"

    def test_x_served_by_as_array(self):
        """X-Served-By as array should be handled for Fastly detection."""
        headers = {"X-Served-By": ["cache-lax17623-LAX", "cache-sjc1234-SJC"]}
        result = call_debug_endpoint("gzip-results", headers)
        assert result["ok"] is True
        assert result["result"] is not None
        assert "Fastly" in result["result"]["message"]


# =============================================================================
# vary_results() tests
# =============================================================================


class TestVaryResults:
    """Tests for VarnishDebug::vary_results()"""

    def test_no_vary_header(self):
        """No Vary header should return empty results."""
        headers = {"Content-Type": "text/html"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert len(result["result"]) == 0

    def test_vary_accept_encoding_no_warning(self):
        """Vary: Accept-Encoding should NOT trigger warning (false positive check)."""
        headers = {"Vary": "Accept-Encoding"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert len(result["result"]) == 0

    def test_vary_accept_triggers_warning(self):
        """Vary: Accept should trigger cache fragmentation warning."""
        headers = {"Vary": "Accept"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert "Vary: Accept" in result["result"]
        assert result["result"]["Vary: Accept"]["icon"] == "warning"
        assert "cache fragmentation" in result["result"]["Vary: Accept"]["message"]

    def test_vary_accept_with_encoding(self):
        """Vary: Accept, Accept-Encoding should still trigger warning."""
        headers = {"Vary": "Accept, Accept-Encoding"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert "Vary: Accept" in result["result"]
        assert result["result"]["Vary: Accept"]["icon"] == "warning"

    def test_vary_accept_case_insensitive(self):
        """Vary detection should be case-insensitive."""
        headers = {"Vary": "accept"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert "Vary: Accept" in result["result"]

    def test_vary_accept_language_no_warning(self):
        """Vary: Accept-Language should NOT trigger warning (false positive check)."""
        headers = {"Vary": "Accept-Language"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert len(result["result"]) == 0

    def test_vary_as_array(self):
        """Vary header as array should be handled (multiple Vary headers)."""
        headers = {"Vary": ["Accept-Encoding", "Accept, Cookie"]}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert "Vary: Accept" in result["result"]
        assert result["result"]["Vary: Accept"]["icon"] == "warning"

    def test_vary_accept_charset_no_warning(self):
        """Vary: Accept-Charset should NOT trigger warning."""
        headers = {"Vary": "Accept-Charset"}
        result = call_debug_endpoint("vary-results", headers)
        assert result["ok"] is True
        assert len(result["result"]) == 0


# =============================================================================
# server_results() tests
# =============================================================================


class TestServerResults:
    """Tests for VarnishDebug::server_results()"""

    def test_no_server_header(self):
        """No Server header should return empty results."""
        headers = {"Age": "10"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert len(result["result"]) == 0

    def test_apache_detected(self):
        """Apache server should be detected."""
        headers = {"Server": "Apache/2.4.41 (Ubuntu)"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "Apache" in result["result"]
        assert result["result"]["Apache"]["icon"] == "awesome"

    def test_nginx_detected(self):
        """Nginx server should be detected."""
        headers = {"Server": "nginx/1.18.0"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]
        assert result["result"]["Nginx"]["icon"] == "awesome"

    def test_cloudflare_server_detected(self):
        """CloudFlare server should show warning."""
        headers = {"Server": "cloudflare"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "CloudFlare" in result["result"]
        assert result["result"]["CloudFlare"]["icon"] == "warning"

    def test_hhvm_detected(self):
        """HHVM should show warning about deprecation."""
        headers = {
            "Server": "Apache",
            "X-Powered-By": "HHVM/3.30.0",
        }
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "HHVM" in result["result"]
        assert result["result"]["HHVM"]["icon"] == "warning"

    def test_pagely_detected(self):
        """Pagely hosting should be detected."""
        headers = {"Server": "Pagely"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "Pagely" in result["result"]

    def test_dreamhost_detected(self):
        """DreamPress should be detected."""
        headers = {"X-Powered-By": "DreamPress"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "DreamHost" in result["result"]
        assert result["result"]["DreamHost"]["icon"] == "awesome"

    def test_wordpress_com_detected(self):
        """WordPress.com should be detected via X-hacker header."""
        headers = {"X-hacker": "If you're reading this..."}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "WordPress.com" in result["result"]
        assert result["result"]["WordPress.com"]["icon"] == "bad"

    def test_godaddy_detected(self):
        """GoDaddy hosting should be detected."""
        headers = {"X-Backend": "wpaas_web_123"}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "GoDaddy" in result["result"]

    def test_server_header_as_array(self):
        """Server header as array should be handled without error.

        This can happen with multiple Server headers (rare but possible).
        """
        headers = {"Server": ["Apache/2.4.41", "Ubuntu"]}
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        assert "Apache" in result["result"]

    def test_x_powered_by_as_array(self):
        """X-Powered-By as array should be handled."""
        headers = {
            "Server": "Apache",
            "X-Powered-By": ["PHP/8.1", "DreamPress"],
        }
        result = call_debug_endpoint("server-results", headers)
        assert result["ok"] is True
        # Should detect DreamPress even when X-Powered-By is array
        assert "DreamHost" in result["result"]
