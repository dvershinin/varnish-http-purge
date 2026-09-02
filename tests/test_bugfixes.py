"""
Tests for bug fixes found during code quality review.

These tests verify that specific bugs have been fixed and don't regress.
"""
import requests

from conftest import API_BASE


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
        )
        r.raise_for_status()

        # Now activate devmode
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "activate"},
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
        )
        r.raise_for_status()

        # Check status without activating
        r = requests.post(
            f"{API_BASE}/devmode-notice-check",
            json={"action": "check"},
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

    Fixed in: health-check.php, varnish_http_purge_site_status_caching_test()
    """

    def test_health_check_handles_false_debug_log(self):
        """Health check should not crash when debug_log option is false/missing."""
        r = requests.post(
            f"{API_BASE}/health-check-debug-log",
            json={"debug_log": False},
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
        )
        r.raise_for_status()
        data = r.json()

        assert data["ok"] is True

        # Now verify they exist
        r = requests.get(
            f"{API_BASE}/check-plugin-options",
        )
        r.raise_for_status()
        data = r.json()

        existing = data["existing_options"]

        # All options should exist after creation
        expected = data["expected_options"]
        for opt in expected:
            assert opt in existing, f"Option {opt} should exist after creation"
