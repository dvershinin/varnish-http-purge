"""Cache and cookie debug detection tests."""

from debug_detection_helpers import call_debug_endpoint


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
        """Cache-Control: max-age=0 without s-maxage should be flagged as bad."""
        headers = {"Cache-Control": "public, max-age=0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "bad"

    def test_max_age_zero_with_smaxage_is_good(self):
        """max-age=0 with s-maxage > 0 should be good."""
        headers = {"Cache-Control": "max-age=0, s-maxage=31536000"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "good"
        assert "s-maxage=31536000" in result["result"]["max_age"]["message"]

    def test_max_age_zero_with_short_smaxage_is_good(self):
        """Even short s-maxage values are valid."""
        headers = {"Cache-Control": "max-age=0, s-maxage=3600"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "good"

    def test_max_age_zero_with_smaxage_zero_is_bad(self):
        """s-maxage=0 provides no benefit, still bad."""
        headers = {"Cache-Control": "max-age=0, s-maxage=0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "bad"

    def test_cache_control_array_with_smaxage(self):
        """Array format should work too."""
        headers = {"Cache-Control": ["public", "max-age=0", "s-maxage=86400"]}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "good"

    def test_max_age_zero_with_cache_hit_evidence_is_warning(self):
        """A proxy that ate s-maxage but proves it is caching should not be an error."""
        headers = {
            "Cache-Control": "max-age=0, public",
            "X-Cache": "HIT",
            "X-Cache-Hits": "17",
            "Age": "956",
        }
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert "max_age" in result["result"]
        assert result["result"]["max_age"]["icon"] == "warning"
        assert "X-Cache: HIT" in result["result"]["max_age"]["message"]
        assert "Age: 956" in result["result"]["max_age"]["message"]

    def test_max_age_zero_with_age_only_is_warning(self):
        """A positive Age alone proves a shared cache stored the response."""
        headers = {"Cache-Control": "max-age=0", "Age": "120"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert result["result"]["max_age"]["icon"] == "warning"

    def test_max_age_zero_with_chained_cache_hits_is_warning(self):
        """Chained proxies report one counter per hop; any non-zero hop counts."""
        headers = {"Cache-Control": "max-age=0", "X-Cache-Hits": "0, 19"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert result["result"]["max_age"]["icon"] == "warning"
        assert "X-Cache-Hits: 19" in result["result"]["max_age"]["message"]

    def test_max_age_zero_with_cache_miss_stays_bad(self):
        """A miss with no age is not evidence of caching."""
        headers = {"Cache-Control": "max-age=0", "X-Cache": "MISS", "Age": "0"}
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert result["result"]["max_age"]["icon"] == "bad"

    def test_max_age_zero_with_array_cache_hit_is_warning(self):
        """Hit indicators in array form should be recognised too."""
        headers = {
            "Cache-Control": ["public", "max-age=0"],
            "X-Cache": ["MISS", "HIT"],
        }
        result = call_debug_endpoint("cache-results", headers)
        assert result["ok"] is True
        assert result["result"]["max_age"]["icon"] == "warning"

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
