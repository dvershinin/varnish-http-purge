#!/usr/bin/env bash
set -euo pipefail

wp() {
  docker compose run --rm wpcli --path=/var/www/html "$@"
}

until docker compose exec -T wordpress curl -sSf http://localhost:8080/wp-admin/install.php >/dev/null 2>&1; do
  echo "Waiting for WordPress to be reachable..."
  sleep 3
done

# Sanity check: verify PHP syntax of test mu-plugin before running tests.
# This catches syntax errors early instead of getting cryptic 500 errors.
if ! docker compose exec -T wordpress php -l /var/www/html/wp-content/mu-plugins/test-control.php >/dev/null 2>&1; then
  echo "ERROR: PHP syntax error in test-control.php:"
  docker compose exec -T wordpress php -l /var/www/html/wp-content/mu-plugins/test-control.php
  exit 1
fi

# Reload Apache to clear OPcache and pick up any PHP file changes.
# This ensures tests run against the latest plugin code.
docker compose exec -T wordpress apachectl graceful 2>/dev/null || true
sleep 1

# WordPress is installed with internal Docker URL - all test traffic uses this
WP_INTERNAL_URL="http://varnish:6081"

if ! wp core is-installed --url="${WP_INTERNAL_URL}"; then
  wp core install \
    --url="${WP_INTERNAL_URL}" \
    --title="Varnish Test" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.com \
    --skip-email
fi

# Ensure uploads directory is writable to avoid install-time warnings
docker compose exec -T wordpress bash -lc 'mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads'

# Set pretty permalinks for plugin requirement
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

# Activate our plugin
wp plugin activate varnish-http-purge

# Create a sample post
if ! wp post list --post_type=post --format=ids | grep -qE '^[0-9]+'; then
  wp post create --post_title="Hello Cache" --post_content="First content" --post_status=publish
fi

# Warm the stack before tests run.
#
# `make tests` reuses containers, but a FRESH stack (and every CI run) is cold:
# OPcache hasn't compiled the plugin/theme, Apache+PHP workers are spinning up,
# MariaDB is cold, and Varnish's cache + ban (purge) machinery is unexercised.
# The early tests (test_cache_basic / _behavior / _tags, first alphabetically)
# do multi-step purge -> render -> cache-state assertions and flake when the
# FIRST render of a given page type is slow (cold OPcache compile mid-sequence).
# A home-only warm-up isn't enough - those tests render single posts, archives,
# feeds, and the cache-tags header path. So we compile every hot path here:
# each page TYPE through Varnish (MISS+HIT), the ban/purge path, and a full
# tags-mode enable -> render -> tag-purge -> disable cycle.
echo "Warming the stack..."
WARM_BACKEND="http://localhost:8080"
WARM_VARNISH="http://varnish:6081"
WARM_API="${WARM_VARNISH}/wp-json/test/v1"
wcurl() { docker compose exec -T wordpress curl -s -o /dev/null "$@" 2>/dev/null || true; }
# Block until the full render path serves a 200 through Varnish.
for i in $(seq 1 20); do
  code=$(docker compose exec -T wordpress curl -s -o /dev/null -w '%{http_code}' "${WARM_VARNISH}/" 2>/dev/null || echo 000)
  [ "$code" = "200" ] && break
  sleep 1
done
# Resolve the sample post URL so we compile the single-post template too.
SAMPLE_URL=$(wp post list --post_type=post --field=url --posts_per_page=1 2>/dev/null | tr -d '\r' | grep -E '^https?://' | head -1)
# Compile every page TYPE the early tests touch (backend = OPcache, then Varnish
# MISS+HIT), and exercise the ban/purge path.
for url in "${WARM_VARNISH}/" "${SAMPLE_URL}" "${WARM_VARNISH}/feed/" "${WARM_VARNISH}/?cat=1"; do
  [ -z "$url" ] && continue
  wcurl "$url"            # MISS (fill + compile template)
  wcurl "$url"            # HIT
done
for i in $(seq 1 2); do
  wcurl -X POST "${WARM_API}/purge" -H 'Content-Type: application/json' -d '{"all":true}'  # warm ban path
  wcurl "${WARM_VARNISH}/"                                                                  # re-fill (MISS)
done
# Warm the cache-tags render + tag-purge code paths (test_cache_tags hits these
# first and they are the most cold-sensitive).
wcurl -X POST "${WARM_API}/tags-mode" -H 'Content-Type: application/json' -d '{"enabled":true}'
[ -n "${SAMPLE_URL}" ] && wcurl "${SAMPLE_URL}"   # render with X-Cache-Tags header (compiles add_headers/get_tags)
wcurl "${WARM_VARNISH}/"
wcurl -X POST "${WARM_API}/purge" -H 'Content-Type: application/json' -d '{"all":true}'
wcurl -X POST "${WARM_API}/tags-mode" -H 'Content-Type: application/json' -d '{"enabled":false}'
# Leave home re-filled so the suite starts from a known-warm state.
wcurl "${WARM_VARNISH}/"
echo "Warm-up complete (home returned HTTP ${code})."

# Show admin review URL if ADMIN_REVIEW_PORT is set (for human access from host)
if [ -n "${ADMIN_REVIEW_PORT:-}" ]; then
  echo "Setup complete. Admin review at http://localhost:${ADMIN_REVIEW_PORT}/wp-admin/ (admin/admin)"
else
  echo "Setup complete. Site URL: ${WP_INTERNAL_URL}"
fi



