# Local Varnish + WordPress testbed

This docker setup spins up:
- MariaDB (port 3306, internal only)
- WordPress with Apache on port 8080 (internal only)
- WP-CLI
- Varnish 7.4 on port 6081 (internal), exposed to host via ADMIN_REVIEW_PORT
- nginx (stable) with nginx-module-cache-purge built from the pinned `2.6.1` tag, three arms on ports 6082 (tags-aware PURGE), 6083 (PURGE without `cache_purge_tags`) and 6084 (PURGE restricted to loopback); see `tests/nginx/default.conf`

The plugin in this repo is mounted into the WordPress container at `wp-content/plugins/varnish-http-purge`.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        Docker Network: vhp_net                          │
│                                                                         │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐ │
│  │    varnish      │      │    wordpress    │      │       db        │ │
│  │   Varnish 7.4   │─────▶│ Apache + PHP8.2 │─────▶│  MariaDB 10.11  │ │
│  │   port 6081     │      │    port 8080    │      │   port 3306     │ │
│  └────────▲────────┘      └─────────────────┘      └─────────────────┘ │
│           │                                                             │
│  ┌────────┴────────┐                                                    │
│  │ tester (pytest) │  All requests: http://varnish:6081                 │
│  │ WP_URL=http://  │  WordPress installed with same URL                 │
│  │ varnish:6081    │  (no Host header tricks needed)                    │
│  └─────────────────┘                                                    │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                    ADMIN_REVIEW_PORT (for human access from host)
                                    │
                                    ▼
                        http://localhost:PORT/wp-admin/
```

## Port Scheme

| Component | Internal Port | Host Port | Purpose |
|-----------|---------------|-----------|---------|
| Varnish   | 6081          | ADMIN_REVIEW_PORT | Human admin access |
| Apache    | 8080          | (none)    | Backend only |
| MariaDB   | 3306          | (none)    | Internal only |

## URL Configuration

- **WordPress site URL**: `http://varnish:6081` (internal Docker URL)
- **pytest WP_URL**: `http://varnish:6081` (same as WordPress)
- **Admin review**: `http://localhost:ADMIN_REVIEW_PORT/wp-admin/` (host access)

## Prereqs
- Docker and Docker Compose
- SSH access to the private `GetPageSpeed/ngx_cache_purge` repository for the nginx image build: `docker compose build --ssh default=$HOME/.ssh/<github-key> nginx` (or keep the key in `ssh-agent`; CI loads the read-only deploy key from the `NGX_CACHE_PURGE_DEPLOY_KEY` secret)

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
