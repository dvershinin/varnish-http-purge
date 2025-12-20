"""
E2E tests for VarnishDebug cache detection logic.

Tests cover:
- varnish_results(): Cache service detection (Varnish, Nginx, Proxy Cache)
- cache_results(): Cache-Control, Age, Pragma header checks
- cookie_results(): Cookie detection
- gzip_results(): Compression detection
- server_results(): Server type detection
- remote_get(): Live header fetching
"""

import os
import pytest
import requests
from urllib.parse import urlparse

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
WP_BACKEND_URL = os.environ.get("WP_BACKEND_URL", WP_URL)
API_BASE = f"{WP_BACKEND_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


def call_debug_endpoint(endpoint: str, headers: dict) -> dict:
    """Call a VarnishDebug test endpoint with the given headers."""
    r = requests.post(
        f"{API_BASE}/debug/{endpoint}",
        json={"headers": headers},
        headers=_host_headers(),
    )
    r.raise_for_status()
    return r.json()


# =============================================================================
# varnish_results() tests - Cache service detection
# =============================================================================


class TestVarnishResults:
    """Tests for VarnishDebug::varnish_results()"""

    def test_no_headers_returns_bad(self):
        """No headers at all should return 'bad' icon with 'not responding' message."""
        result = call_debug_endpoint("varnish-results", {})
        assert result["ok"] is True
        assert result["result"]["icon"] == "bad"
        # When no headers are provided, the site is not responding.
        assert "not responding" in result["result"]["message"]

    def test_no_cache_service_detected(self):
        """Headers present but no cache indicators should return 'no cache service' message.
        
        This is now a 'warning' (not 'bad') because cache detection relies on heuristics
        and the plugin will still send purge requests even if it can't detect the cache.
        """
        # Some headers are present (site is responding) but no cache-related ones.
        headers = {"Server": "Apache", "Content-Type": "text/html"}
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "warning"
        assert "No known cache service" in result["result"]["message"]

    def test_x_varnish_with_two_ids_is_hit(self):
        """X-Varnish with 2 transaction IDs = cache HIT."""
        # Two IDs means: this request's ID + the cached object's ID
        headers = {
            "X-Varnish": "32770 32771",
            "Age": "42",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_x_varnish_with_one_id_is_miss(self):
        """X-Varnish with 1 transaction ID = cache MISS."""
        # Single ID means: only this request's ID (no cached object)
        headers = {
            "X-Varnish": "32770",
            "Age": "0",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        # MISS with Age=0, but X-Cache: HIT could still save it
        assert result["result"]["icon"] == "warning"

    def test_x_varnish_miss_with_x_cache_hit(self):
        """X-Varnish MISS (1 ID) but X-Cache: HIT should still be awesome."""
        headers = {
            "X-Varnish": "32770",  # Single ID = MISS from Varnish
            "Age": "0",
            "X-Cache": "HIT",  # But CDN/proxy cached it
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_x_varnish_miss_with_age_zero_returns_warning(self):
        """X-Varnish MISS with Age=0 and no other HIT indicator returns warning."""
        headers = {
            "X-Varnish": "32770",  # Single ID = MISS
            "Age": "0",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "warning"
        assert "Age header" in result["result"]["message"]

    def test_x_cache_hit_with_age_returns_awesome(self):
        """X-Cache: HIT with Age > 0 should return 'awesome'."""
        headers = {
            "X-Cache": "HIT",
            "Age": "10",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"
        assert "Proxy Cache" in result["result"]["message"]

    def test_x_cache_miss_with_age_returns_warning(self):
        """X-Cache: MISS should not trigger HIT detection."""
        headers = {
            "X-Cache": "MISS",
            "Age": "0",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        # No HIT indicator, should be bad or warning
        assert result["result"]["icon"] in ("bad", "warning")

    def test_via_varnish_header_detected(self):
        """Via header containing 'varnish' should detect Varnish."""
        headers = {
            "Via": "1.1 varnish (Varnish/6.0)",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"
        assert "Varnish" in result["result"]["message"]

    def test_via_array_with_varnish(self):
        """Via header as array should still detect varnish."""
        headers = {
            "Via": ["1.1 proxy", "1.1 varnish"],
            "Age": "3",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_nginx_with_varnish_detected_as_varnish(self):
        """Nginx server with X-Varnish header should be detected as Varnish (not Nginx)."""
        # This is the case where Nginx is the web server but Varnish is the cache.
        headers = {
            "server": "nginx/1.18.0",
            "X-Varnish": "32770 32771",  # Two IDs = HIT
            "Age": "15",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        # Should detect Varnish, not Nginx, because X-Varnish is present
        assert "Varnish" in result["result"]["message"]
        assert result["result"]["icon"] == "awesome"

    def test_nginx_fastcgi_cache_detected(self):
        """Nginx with FastCGI cache (X-Cache, no X-Varnish) should be detected as Nginx."""
        headers = {
            "server": "nginx/1.18.0",
            "X-Cache": "HIT",
            "Age": "10",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]
        assert result["result"]["icon"] == "awesome"

    def test_openresty_fastcgi_cache_detected(self):
        """OpenResty with its own caching should be detected as Nginx."""
        headers = {
            "server": "openresty/1.19.3.1",
            "X-Cache": "HIT",
            "Age": "10",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]

    def test_nginx_proxy_cache_detected(self):
        """Nginx with proxy_cache (X-Proxy-Cache) should be detected as Nginx."""
        headers = {
            "server": "nginx/1.18.0",
            "X-Proxy-Cache": "HIT",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]

    def test_nginx_cache_status_detected(self):
        """Nginx with X-Cache-Status: HIT should be detected as Nginx."""
        headers = {
            "server": "nginx/1.18.0",
            "x-cache-status": "HIT",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]

    def test_x_cacheable_yes_with_varnish(self):
        """X-Cacheable: YES should be recognized as working cache."""
        headers = {
            "X-Varnish": "32770",  # Single ID = technically MISS
            "X-Cacheable": "YES",  # But X-Cacheable says it's cacheable
            "Age": "0",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_x_cache_status_hit_detected(self):
        """X-Cache-Status: HIT should be included in detection."""
        headers = {
            "x-cache-status": "HIT",
            "X-Varnish": "32770 32771",  # Two IDs = HIT
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_x_proxy_cache_hit_detected(self):
        """X-Proxy-Cache: HIT should be included in detection."""
        headers = {
            "X-Proxy-Cache": "HIT",
            "X-Varnish": "32770 32771",  # Two IDs = HIT
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert result["result"]["icon"] == "awesome"

    def test_x_varnish_as_array(self):
        """X-Varnish header as array (multiple proxies) should be handled."""
        # When passing through multiple Varnish instances, each adds its ID
        headers = {
            "X-Varnish": ["32770", "32771"],  # Two IDs = HIT
            "Age": "10",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Varnish" in result["result"]["message"]
        assert result["result"]["icon"] == "awesome"

    def test_via_header_case_insensitive(self):
        """Via header detection should find 'Varnish' regardless of case."""
        headers = {
            "Via": "1.1 vARnish (Varnish/7.0)",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Varnish" in result["result"]["message"]

    def test_x_cache_status_uppercase(self):
        """X-Cache-Status with uppercase should be detected."""
        headers = {
            "X-Cache-Status": "HIT",
            "server": "nginx/1.18.0",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]

    def test_x_cache_status_lowercase(self):
        """x-cache-status with lowercase should be detected."""
        headers = {
            "x-cache-status": "HIT",
            "server": "nginx/1.18.0",
            "Age": "5",
        }
        result = call_debug_endpoint("varnish-results", headers)
        assert result["ok"] is True
        assert "Nginx" in result["result"]["message"]


# =============================================================================
# cache_results() tests - Cache-Control, Age, Pragma
# =============================================================================


class TestCacheResults:
    """Tests for VarnishDebug::cache_results()"""

    def test_no_cache_control_header(self):
        """Missing Cache-Control should not trigger warnings about it."""
        headers = {"Age": "10"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        # Should have Age check but no Cache-Control warnings
        assert "No Cache Header" not in result["result"]
        assert "max_age" not in result["result"]

    def test_cache_control_no_cache_detected(self):
        """Cache-Control: no-cache should be flagged as bad."""
        headers = {"Cache-Control": "no-cache, no-store"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "No Cache Header" in result["result"]
        assert result["result"]["No Cache Header"]["icon"] == "bad"

    def test_cache_control_max_age_zero_detected(self):
        """Cache-Control: max-age=0 should be flagged as bad."""
        headers = {"Cache-Control": "public, max-age=0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "bad"

    def test_cache_control_array_format(self):
        """Cache-Control as array should be handled correctly."""
        headers = {"Cache-Control": ["public", "max-age=0"]}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        # max-age=0 should be detected even in array format
        assert "max_age" in result["result"]

    def test_age_header_missing(self):
        """Missing Age header should be flagged as bad."""
        headers = {"Cache-Control": "public, max-age=3600"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Age Headers" in result["result"]
        assert result["result"]["Age Headers"]["icon"] == "bad"
        assert "does not report" in result["result"]["Age Headers"]["message"]

    def test_age_header_zero(self):
        """Age: 0 should be flagged as warning."""
        headers = {"Age": "0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Age Headers" in result["result"]
        assert result["result"]["Age Headers"]["icon"] == "warning"
        assert "returning 0" in result["result"]["Age Headers"]["message"]

    def test_age_header_positive(self):
        """Age > 0 should be flagged as awesome."""
        headers = {"Age": "42"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Age Headers" in result["result"]
        assert result["result"]["Age Headers"]["icon"] == "awesome"

    def test_pragma_no_cache_detected(self):
        """Pragma: no-cache should be flagged as bad."""
        headers = {"Pragma": "no-cache", "Age": "10"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Pragma Headers" in result["result"]
        assert result["result"]["Pragma Headers"]["icon"] == "bad"

    def test_x_cache_status_miss_detected(self):
        """X-Cache-Status: MISS should be flagged."""
        headers = {"X-Cache-Status": "MISS", "Age": "0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "X-Cache-Status" in result["result"]
        assert result["result"]["X-Cache-Status"]["icon"] == "bad"

    def test_cloudflare_cache_miss(self):
        """cf-cache-status: MISS should show warning."""
        headers = {"cf-cache-status": "MISS", "Age": "0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "CloudFlare Cache" in result["result"]
        assert result["result"]["CloudFlare Cache"]["icon"] == "warning"

    def test_cloudflare_cache_dynamic(self):
        """cf-cache-status: DYNAMIC should show good."""
        headers = {"cf-cache-status": "DYNAMIC", "Age": "10"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "CloudFlare Cache" in result["result"]
        assert result["result"]["CloudFlare Cache"]["icon"] == "good"

    def test_cloudflare_cache_hit_apo_warning(self):
        """cf-cache-status: HIT should warn about APO."""
        headers = {"cf-cache-status": "HIT", "Age": "10"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "CloudFlare Cache" in result["result"]
        assert result["result"]["CloudFlare Cache"]["icon"] == "warning"
        assert "APO" in result["result"]["CloudFlare Cache"]["message"]

    def test_mod_pagespeed_with_cacheable_forced(self):
        """Mod Pagespeed with X-Cacheable: YES:Forced should be good."""
        headers = {
            "X-Mod-Pagespeed": "1.13.35.2-0",
            "X-Cacheable": "YES:Forced",
            "Age": "10",
        }
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Mod Pagespeed" in result["result"]
        assert result["result"]["Mod Pagespeed"]["icon"] == "good"

    def test_mod_pagespeed_without_cacheable_forced(self):
        """Mod Pagespeed without proper cacheable header should warn."""
        headers = {
            "X-Mod-Pagespeed": "1.13.35.2-0",
            "Age": "10",
        }
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "Mod Pagespeed" in result["result"]
        assert result["result"]["Mod Pagespeed"]["icon"] == "bad"


# =============================================================================
# cookie_results() tests
# =============================================================================


class TestCookieResults:
    """Tests for VarnishDebug::cookie_results()"""

    def test_no_cookies_is_awesome(self):
        """No Set-Cookie header should return awesome."""
        headers = {"Cache-Control": "public"}
        result = call_debug_endpoint("cookie-results", headers)
        assert result["ok"] is True
        assert "No Cookies" in result["result"]
        assert result["result"]["No Cookies"]["icon"] == "awesome"

    def test_cookies_found_warning(self):
        """Set-Cookie present should show warning."""
        headers = {"Set-Cookie": "session_id=abc123; path=/"}
        result = call_debug_endpoint("cookie-results", headers)
        assert result["ok"] is True
        assert "Cookies Found" in result["result"]
        assert result["result"]["Cookies Found"]["icon"] == "warning"

    def test_cookies_array_handled(self):
        """Multiple Set-Cookie headers as array should be handled."""
        headers = {
            "Set-Cookie": [
                "session_id=abc123; path=/",
                "preferences=dark; path=/",
            ]
        }
        result = call_debug_endpoint("cookie-results", headers)
        assert result["ok"] is True
        assert "Cookies Found" in result["result"]


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


# =============================================================================
# cache_results() tests with array headers
# =============================================================================


class TestCacheResultsArrayHeaders:
    """Tests for VarnishDebug::cache_results() with array-valued headers."""

    def test_pragma_header_as_array(self):
        """Pragma header as array should be handled without error."""
        headers = {"Pragma": ["no-cache", "no-store"], "Age": "10"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        # Should detect no-cache even in array format
        assert "Pragma Headers" in result["result"]
        assert result["result"]["Pragma Headers"]["icon"] == "bad"


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
            headers=_host_headers(),
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
            headers=_host_headers(),
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

