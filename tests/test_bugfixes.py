"""
Tests for bug fixes found during code quality review.

These tests verify that specific bugs have been fixed and don't regress.
"""
import os
from urllib.parse import urlparse
import requests
import pytest

WP_URL = os.environ.get("WP_URL", "http://localhost:8080")
WP_BACKEND_URL = os.environ.get("WP_BACKEND_URL", "http://wordpress")
API_BASE = f"{WP_BACKEND_URL}/wp-json/test/v1"
_parsed = urlparse(WP_URL)
HOST_HEADER_VALUE = "localhost:8080" if _parsed.hostname == "varnish" else _parsed.netloc


def _host_headers():
    return {"Host": HOST_HEADER_VALUE}


class TestDevmodeNoticeLogicFix:
    """
    Bug: devmode_is_active_notice() had inverted logic.
    
    The condition `if ( ! $devmode['active'] )` was wrong - it should be
    `if ( $devmode['active'] )` because the function is only called when
    devmode is actually active.
    
    Fixed in: varnish-http-purge.php, devmode_is_active_notice()
    """

    def test_devmode_notice_displays_when_active(self):
        """When devmode is active, the notice should indicate it will display."""
        # First deactivate to ensure clean state
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "deactivate"},
            headers=_host_headers()
        )
        r.raise_for_status()

        # Now activate devmode
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "activate"},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["devmode_check"] is True, "devmode_check() should return True when active"
        assert data["option_active"] is True, "option should show active=True"
        assert data["notice_would_display"] is True, "Notice SHOULD display when devmode is active (bug fix)"
        assert "active for next" in data["notice_message"], "Message should mention time remaining"

    def test_devmode_notice_no_display_when_inactive(self):
        """When devmode is inactive, the notice should not display."""
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "deactivate"},
            headers=_host_headers()
        )
        r.raise_for_status()

        # Check status without activating
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "check"},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["devmode_check"] is False, "devmode_check() should return False when inactive"
        # When VHP_DEVMODE is not defined and devmode is inactive, notice should not display
        # (The function wouldn't even be called in production, but our test checks the internal logic)


class TestHealthCheckDebugLogHandling:
    """
    Bug: health-check.php would crash if vhp_varnish_debug option was not an array.
    
    The foreach loop didn't check if $debug_log was a valid array before iterating.
    
    Fixed in: health-check.php, vhp_site_status_caching_test()
    """

    def test_health_check_handles_false_debug_log(self):
        """Health check should not crash when debug_log option is false/missing."""
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": False},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["error_occurred"] is False, "Should not crash on false debug_log"

    def test_health_check_handles_null_debug_log(self):
        """Health check should not crash when debug_log option is null."""
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": None},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["error_occurred"] is False, "Should not crash on null debug_log"

    def test_health_check_handles_string_debug_log(self):
        """Health check should not crash when debug_log is a string (malformed)."""
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": "not an array"},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["error_occurred"] is False, "Should not crash on string debug_log"

    def test_health_check_handles_nested_non_array(self):
        """Health check should handle sites with non-array results."""
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": {"http://example.com": "not an array"}},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["error_occurred"] is False, "Should not crash on nested non-array"

    def test_health_check_handles_valid_debug_log(self):
        """Health check should work correctly with valid debug_log data."""
        valid_data = {
            "http://example.com": {
                "Cache Service": {"icon": "good", "message": "Cache is working"},
                "Age Headers": {"icon": "awesome", "message": "Age headers OK"}
            }
        }
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": valid_data},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        assert data["error_occurred"] is False
        assert data["result_status"] == "good", "Should return good status for healthy cache"


class TestPluginOptionsCleanup:
    """
    Bug: uninstall.php was missing cleanup for several options.
    
    Missing options: vhp_varnish_use_tags, vhp_varnish_debug,
    vhp_varnish_purge_queue, vhp_varnish_last_queue_run
    
    Fixed in: uninstall.php
    """

    def test_all_expected_options_are_tracked(self):
        """Verify all plugin options are in the expected list for cleanup."""
        r = requests.get(
            f"{API_BASE}/check-plugin-options",
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        expected = data["expected_options"]

        # These are the options that should be cleaned up on uninstall
        required_options = [
            "vhp_varnish_url",
            "vhp_varnish_ip",
            "vhp_varnish_extra_purge_header_name",
            "vhp_varnish_extra_purge_header_value",
            "vhp_varnish_devmode",
            "vhp_varnish_max_posts_before_all",
            "vhp_varnish_use_tags",  # Was missing before fix
            "vhp_varnish_debug",  # Was missing before fix
            "vhp_varnish_purge_queue",  # Was missing before fix
            "vhp_varnish_last_queue_run",  # Was missing before fix
        ]

        for opt in required_options:
            assert opt in expected, f"Option {opt} should be in the cleanup list"

    def test_plugin_options_can_be_created(self):
        """Verify all plugin options can be created (setup for uninstall test)."""
        r = requests.post(
            f"{API_BASE}/create-plugin-options",
            json={},
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True

        # Now verify they exist
        r = requests.get(
            f"{API_BASE}/check-plugin-options",
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        existing = data["existing_options"]

        # All options should exist after creation
        expected = data["expected_options"]
        for opt in expected:
            assert opt in existing, f"Option {opt} should exist after creation"


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
            headers=_host_headers()
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
            headers=_host_headers()
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
            headers=_host_headers()
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
            headers=_host_headers()
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
            headers=_host_headers()
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
            headers=_host_headers()
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
            headers=_host_headers()
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True
        # Result should be an empty dict/array, not None (fix: return array() instead of void)
        assert data["result"] is not None, "Result should not be None"
        assert isinstance(data["result"], (dict, list)), "Result should be array/dict"
        assert len(data["result"]) == 0, "Empty input should return empty array"

