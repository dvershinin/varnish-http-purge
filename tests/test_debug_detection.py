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

from debug_detection_helpers import call_debug_endpoint


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
