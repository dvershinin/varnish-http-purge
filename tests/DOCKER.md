# Local Varnish + WordPress testbed

This docker setup spins up:
- MariaDB
- WordPress (Apache, PHP 8.2)
- WP-CLI
- Varnish 7 (listening on localhost:8080)

The plugin in this repo is mounted into the WordPress container at `wp-content/plugins/varnish-http-purge`.

## Prereqs
- Docker and Docker Compose

## Usage

```bash
make up         # start services
make setup      # install WP, enable permalinks, activate plugin
make test       # run a simple cache HIT -> PURGE -> MISS flow via Varnish (legacy)
make tests      # run pytest suite (preferred)
make pytest     # alias to run pytest directly
make logs       # follow logs
make down       # stop and remove containers
make clean      # also remove volumes
```

Visit `http://localhost:8080` through Varnish.

## Notes
- The plugin uses `define('VHP_VARNISH_IP', 'varnish');` via WP-config extra, so PURGE requests are sent to the `varnish` service, not to the site host.
- `default.vcl` supports `PURGE` and regex bans (`X-Purge-Method: regex`).
- Headers `X-Cache` and `X-Cache-Hits` are set on delivery to verify caching.

## Tests

The `tester` service runs Python `pytest` inside Docker and relies on an MU plugin providing minimal REST control endpoints.

Run tests:

```bash
make tests
```

Notes:
- Tests live under `tests/`.
- `tests/mu-plugins/test-control.php` exposes REST endpoints to change permalink structure and create/update posts.

For isolated local runs without the project root compose, you can use `tests/docker-compose.yml` and `tests/setup.sh`:

```bash
cd tests
docker compose up -d
bash setup.sh
docker compose run --rm tester -q
docker compose down -v
```
