"""
Regression tests for the SSRF hardening of the manual `vhp_flush_do` purge
handler (privately reported by Austin Ginder, June 2026).

The vulnerability: `execute_purge()` purged whatever URL was passed in
`?vhp_flush_do=`, gated only by the shared `vhp-flush-do` nonce. Because a WP
nonce is not bound to the query-arg value, a low-privileged (`edit_published_posts`)
user could reuse the admin-bar purge nonce to point the server's outbound PURGE
request at an arbitrary host - a blind internal-host reachability oracle.

The fix restricts the manual handler to URLs on the site's own host, and adds a
capability gate (filter: `vhp_manual_purge_capability`) on top of the nonce.

The `/simulate-flush-do` test endpoint drives the real `execute_purge()` handler
and reports which URLs it actually attempted to purge (observed via the
`after_purge_url` action). A non-local target must purge nothing; a same-site
target must still purge.
"""
from urllib.parse import urlparse
import requests

from conftest import WP_URL, API_BASE

# The admin user created by setup.sh has ID 1 (edit_published_posts capable).
ADMIN_USER_ID = 1


def _simulate(target: str, as_user=None) -> dict:
    """Invoke the real vhp_flush_do handler via the test endpoint.

    Args:
        target: The value to feed into `?vhp_flush_do=`.
        as_user: WordPress user ID to run the handler as, or None to run as the
            unauthenticated user 0 (exercises the capability gate).

    Returns:
        The decoded JSON response, containing `target` and the `purged` list of
        URLs the handler actually sent a PURGE request to.
    """
    payload = {"target": target}
    if as_user is not None:
        payload["as_user"] = as_user
    r = requests.post(f"{API_BASE}/simulate-flush-do", json=payload)
    r.raise_for_status()
    return r.json()


def test_external_host_is_not_purged():
    """A foreign host in vhp_flush_do must never trigger an outbound purge."""
    result = _simulate("http://attacker.invalid/", as_user=ADMIN_USER_ID)
    assert result["purged"] == [], (
        f"External host should be rejected before purging, got: {result['purged']}"
    )


def test_internal_host_probe_is_not_purged():
    """An internal-host SSRF probe target must be rejected too."""
    result = _simulate("http://127.0.0.1:9000/", as_user=ADMIN_USER_ID)
    assert result["purged"] == [], (
        f"Internal host should be rejected before purging, got: {result['purged']}"
    )


def test_local_same_site_url_still_purges():
    """A URL on the site's own host must still be purged normally."""
    host = urlparse(WP_URL).netloc.split(":")[0]
    target = f"{WP_URL}/sample-page/"
    result = _simulate(target, as_user=ADMIN_USER_ID)
    assert len(result["purged"]) >= 1, (
        f"Same-site URL (host {host}) should still purge, got: {result['purged']}"
    )


def test_capability_gate_blocks_unauthorized_user():
    """Without the required capability, even a valid same-site URL must not purge."""
    # No as_user => current user 0, which lacks edit_published_posts.
    result = _simulate(f"{WP_URL}/sample-page/")
    assert result["purged"] == [], (
        f"Manual purge must require a capability, not just a nonce, got: {result['purged']}"
    )
