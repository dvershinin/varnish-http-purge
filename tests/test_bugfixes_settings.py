"""Regression tests for settings sanitization bug fixes."""

import requests

from conftest import API_BASE


class TestSettingsSanitization:
    """
    Bug: Settings sanitize functions had issues:
    1. is_int() check on form input always fails (strings from forms)
    2. Returning void on empty input could cause issues

    Fixed in: settings.php
    """

    def test_maxposts_empty_input_returns_existing(self):
        """Empty maxposts input should return existing value, not void."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "maxposts", "value": ""},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        # Result should be the existing value, not None/void
        assert data["result"] is not None, "Result should not be None (fix: return existing instead of void)"
        # Database may return as string or int depending on how it was stored
        assert int(data["result"]) == int(data["existing"]), "Empty input should return existing value"

    def test_maxposts_valid_number_string(self):
        """Maxposts should accept numeric strings from forms."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "maxposts", "value": "75"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["result"] == 75, "Should parse string '75' as integer 75"

    def test_ip_empty_input_returns_empty_string(self):
        """Empty IP input should return empty string (clear the setting)."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "ip", "value": ""},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["result"] == "", "Empty input should return empty string (fix: return '' instead of void)"

    def test_ip_valid_input_works(self):
        """Valid IP input should be sanitized and returned."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "ip", "value": "192.168.1.1"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["result"] == "192.168.1.1"

    def test_ip_multiple_ips(self):
        """Multiple IPs separated by comma should be handled."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "ip", "value": "192.168.1.1, 10.0.0.1"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert "192.168.1.1" in data["result"]
        assert "10.0.0.1" in data["result"]

    def test_devmode_expire_from_form_is_numeric_string(self):
        """Devmode expire from form is a string, should be handled correctly."""
        expire_timestamp = "1735689600"  # Some future timestamp as string
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={
                "type": "devmode",
                "value": {"active": True, "expire": expire_timestamp}
            },
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["result"] is not None, "Result should not be None"
        # The expire should be converted to an integer (fix: use is_numeric instead of is_int)
        assert isinstance(data["result"].get("expire"), int), "Expire should be an integer"
        assert data["result"]["expire"] == 1735689600, "String timestamp should be parsed as int"
        assert data["result"]["active"] is True, "Active should be True"

    def test_devmode_empty_input_returns_empty_array(self):
        """Empty devmode input should return empty array, not void."""
        r = requests.post(
            f"{API_BASE}/test-settings-sanitize",
            json={"type": "devmode", "value": ""},
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        # Result should be an empty dict/array, not None (fix: return array() instead of void)
        assert data["result"] is not None, "Result should not be None"
        assert isinstance(data["result"], (dict, list)), "Result should be array/dict"
        assert len(data["result"]) == 0, "Empty input should return empty array"


class TestHealthScoreSchemeWithVarnishIP:
    """
    Bug: Health score loopback test preserved the site's HTTPS scheme when
    rewriting the URL with VHP_VARNISH_IP. This caused HTTPS requests to a
    plain HTTP Varnish daemon, resulting in a misleading "server may block
    loopback requests" error.

    Fixed in: settings.php, ajax_health_score() - force http:// when
    VHP_VARNISH_IP is set.
    """

    def test_https_site_with_http_varnish_ip_uses_http(self):
        """HTTPS site URL must be rewritten to http:// when targeting Varnish IP."""
        r = requests.post(
            f"{API_BASE}/health-score-url-rewrite",
            json={"url": "https://example.com/some-page/", "varnish_ip": "127.0.0.1:57005"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["rewritten"] == "http://127.0.0.1:57005/some-page/", (
            f"Expected http:// scheme for Varnish IP, got: {data['rewritten']}"
        )

    def test_http_site_with_varnish_ip_stays_http(self):
        """HTTP site URL should remain http:// when rewritten for Varnish IP."""
        r = requests.post(
            f"{API_BASE}/health-score-url-rewrite",
            json={"url": "http://example.com/", "varnish_ip": "192.168.1.100:6081"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["rewritten"] == "http://192.168.1.100:6081/", (
            f"Expected http:// scheme, got: {data['rewritten']}"
        )

    def test_url_with_query_string_preserved(self):
        """Query strings must survive the URL rewrite."""
        r = requests.post(
            f"{API_BASE}/health-score-url-rewrite",
            json={"url": "https://example.com/page/?foo=bar", "varnish_ip": "127.0.0.1:6081"},
        )
        r.raise_for_status()
        data = r.json()

        assert data["rewritten"] == "http://127.0.0.1:6081/page/?foo=bar", (
            f"Query string lost in rewrite: {data['rewritten']}"
        )
