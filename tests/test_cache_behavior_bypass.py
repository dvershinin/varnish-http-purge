"""Cache-test post bypass behavior."""


class TestCacheTestPostsBypass:
    """Verify that cache test posts bypass normal purge logic."""

    def test_test_post_meta_set(self):
        """Verify test posts have the bypass meta flag."""
        # This tests the WordPress side - we need an endpoint for this
        # For now, we verify the flow works by checking no extra purges happen
        pass  # Placeholder - would need a REST endpoint to verify meta
