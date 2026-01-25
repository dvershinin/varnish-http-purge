#!/usr/bin/env bash
set -euo pipefail

wp() {
  docker compose run --rm wpcli --path=/var/www/html "$@"
}

until docker compose exec -T wordpress curl -sSf http://localhost:8080/wp-admin/install.php >/dev/null 2>&1; do
  echo "Waiting for WordPress to be reachable..."
  sleep 3
done

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

# Show admin review URL if ADMIN_REVIEW_PORT is set (for human access from host)
if [ -n "${ADMIN_REVIEW_PORT:-}" ]; then
  echo "Setup complete. Admin review at http://localhost:${ADMIN_REVIEW_PORT}/wp-admin/ (admin/admin)"
else
  echo "Setup complete. Site URL: ${WP_INTERNAL_URL}"
fi



