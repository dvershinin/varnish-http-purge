# Project Guidelines

## Coding Standards

*PHP and WordPress coding standards for WordPress plugins* | Applies to: `*.php, *.txt, *.md`

## PHP Code Quality

1. **Always define variables before use**
   - Every variable must be initialized before being used in conditionals
   - When copying logic between functions, ensure ALL required variables are included

2. **Variable Naming (WordPress snake_case)**
   - Use snake_case for all variables: `$file_cache_dir` not `$fileCacheDir`
   - Use snake_case for function names: `get_linux_distro` not `getLinuxDistro`

3. **String Interpolation (PHP 8.2+)**
   - Use `{$var}` syntax, not deprecated `${var}` syntax
   - ❌ Wrong: `"path ${dir}/file"`
   - ✅ Correct: `"path {$dir}/file"`

4. **WordPress Functions**
   - Use `esc_html_e()` for translatable escaped output
   - Use `esc_attr()` for attribute values
   - Use `wp_kses_post()` for HTML content
   - Use `sanitize_file_name()` or `sanitize_text_field()` for user input
   - Use `wp_unslash()` before sanitizing `$_REQUEST` data

5. **Before committing PHP changes**
   - Run `make phpstan` to catch undefined variables
   - Run `make phpcs` to check WordPress coding standards
   - Run `make lint` to run all checks

## Text Files (readme.txt, changelog.txt)

1. **Never use escaped quotes in .txt or .md files**
   - ❌ Wrong: `\"Use this feature\"`
   - ✅ Correct: `"Use this feature"`

2. **When outputting quotes in PHP `esc_html_e()`**
   - ❌ Wrong: `esc_html_e( 'Example: key=\"value\"' )`
   - ✅ Correct: `esc_html_e( 'Example: key="value"' )`
   - The function handles escaping for HTML output

## Pre-commit Checks

Before committing, run:
```bash
make lint        # Run all linting checks
make check-txt   # Check text files for issues
make tests       # Run full test suite
```

## Common Mistakes to Avoid

1. **Copy-paste between functions**: When copying code between methods, verify all variables used are defined in the new context

2. **String escaping confusion**: 
   - In `.txt` files: Use literal quotes `"`
   - In PHP single-quoted strings: Use literal quotes `"`  
   - In PHP double-quoted strings: Escape with `\"` only when needed

3. **Missing variable initialization**: Always initialize variables at function start, especially in callback functions that mirror other functions' logic

4. **Deprecated PHP syntax**: Use `{$var}` not `${var}` in string interpolation (deprecated in PHP 8.2)

---

## Rules

Any code change should be backed up by running tests. Existing tests should stay green.
If code change benefits from a new test, add it and ensure the whole testsuite stays green.

**Test iteration rule:** When fixing tests, use `make pytest-one TEST=name` instead of `make tests`. Only run `make tests` once at the start (to get stack up) and once at the end (to verify everything passes).

---

## Vhp Test Config

## Varnish HTTP Purge Test Configuration

### Test Stack Architecture

**Pytest runs inside Docker** on the same network as other services. All test traffic stays within Docker.

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
│  │ tester (pytest) │  All requests go through Varnish                   │
│  │ WP_URL=http://  │  (mu-plugin sends no-cache headers for API)        │
│  │ varnish:6081    │                                                    │
│  └─────────────────┘                                                    │
└─────────────────────────────────────────────────────────────────────────┘
```

### Internal Ports (Docker network only)

| Component | Port | Notes |
|-----------|------|-------|
| **Varnish** | 6081 | Entry point for all test requests |
| **Apache** | 8080 | WordPress backend |
| **MariaDB** | 3306 | Database |

### URL Configuration

From `tests/conftest.py`:

```python
WP_URL = os.environ.get("WP_URL", "http://localhost:64800")
API_BASE = f"{WP_URL}/wp-json/test/v1"
```

**Inside Docker** (tester container):
- `WP_URL=http://varnish:6081` - set via docker-compose.yml

### Why No Separate Backend URL?

The test mu-plugin (`tests/mu-plugins/test-control.php`) sends `Cache-Control: no-cache` headers on all `/wp-json/test/v1/` responses, so Varnish never caches API calls.

### Request Flow

```
tester → varnish:6081 → wordpress:8080 → db:3306
```

- Frontend pages: cached by Varnish
- API requests (`/wp-json/test/v1/*`): pass through but not cached

### Varnish Backend (tests/varnish/default.vcl)

```vcl
backend default {
    .host = "wordpress";
    .port = "8080";
}
```

### Important Rules

1. **All test traffic stays inside Docker** - uses internal DNS names
2. **NEVER define WP_URL or API_BASE locally in test files** - import from conftest.py
3. **No nginx in the stack** - Varnish connects directly to Apache

---

## Wp Test Stack

## WordPress test stack & wp-admin access

- **When starting or reusing the Docker test stack**
  - Run either `make tests` from the plugin root, or from `tests/` run:
    - `export TEST_PORT=${DEFAULT_TEST_PORT} && docker compose up -d`
    - `TEST_PORT=${DEFAULT_TEST_PORT} bash setup.sh`
  - After the stack is up, always detect the current home URL from inside the container:
    - `cd tests && docker compose run --rm wpcli --path=/var/www/html option get home`
  - Parse the port from that URL and surface to the user:
    - Site URL: `http://localhost:PORT/`
    - Admin URL: `http://localhost:PORT/wp-admin/`
    - Credentials: `admin` / `admin` (from `tests/setup.sh`).

- **When the user asks to open or inspect wp-admin / settings UI**
  - Check stack status first with `cd tests && docker compose ps`.
  - If WordPress is not running or not healthy:
    - From the plugin root: `make up && make setup`, or
    - From `tests/`: `export TEST_PORT=${DEFAULT_TEST_PORT} && docker compose up -d` then `bash setup.sh`.
  - Then repeat the home-URL detection step above and reply with the exact current admin URL and credentials.

- **After every `make tests` run**
  - Assume the stack may have been recreated on a new random port (because `TEST_PORT` is regenerated).
  - Recompute the home/admin URLs as above and explicitly tell the user:
    - `WordPress is now at http://localhost:PORT/wp-admin/ (admin/admin)`.
  - Do not assume old ports (like 8080 or an earlier TEST_PORT) are still valid once tests have been rerun.

## Test Environment Details

- **Docker services**: db (MariaDB), wordpress (PHP with OPcache), wpcli, tester (pytest)
- **Test helper plugin**: `tests/mu-plugins/test-control.php` provides REST endpoints for testing
- **Common test API endpoints** (at `/wp-json/test/v1/`):
  - `GET /opcache-status` - Get OPcache configuration and statistics
  - `POST /opcache-reset` - Trigger OPcache reset
  - `POST /simulate-update` - Trigger `upgrader_process_complete` hook
  - `POST /simulate-plugin-delete` - Trigger `deleted_plugin` hook
  - `POST /post` - Create a test post

## Running Tests

```bash
# From plugin root
make tests           # Full run with -x (stops on first failure)
make pytest          # Just run pytest (assumes stack is already up)
make pytest-one TEST=test_cache_basic  # Run single test file/pattern
make pytest-v        # Verbose output (still stops on first failure)
make pytest-all      # Run ALL tests, don't stop on first failure

# From tests/ directory
docker compose up -d
bash setup.sh
docker compose run --rm tester -v

# Cleanup
make clean           # Stop stack and remove volumes
```

## Fast Iteration Rules

**When fixing flaky tests or debugging:**

1. **NEVER run `make tests` repeatedly** - it's slow (rebuilds, restarts stack)
2. **Use `make pytest-one TEST=test_name`** to iterate on a single test
3. **Use `make pytest`** when stack is already up
4. **Tests stop on first failure** by default (`-x` flag in pytest.ini)
5. **PHP changes are picked up immediately** - no OPcache delay (revalidate_freq=0)

**Workflow for fixing a failing test:**
```bash
make tests              # Run once to get the stack up + find first failure
make pytest-one TEST=test_that_failed   # Iterate on the fix
make pytest             # Verify fix doesn't break others
```

## Test Code Guidelines

**NEVER use `time.sleep()` in tests.** Tests must use polling/retry helpers instead:

- Use `wait_for_cache_hit(url)` to wait for cache to warm up
- Use `wait_for_cache_miss(url)` to wait for cache invalidation
- Use retry loops with explicit timeout and condition checks
- If a test depends on timing, use `pytest.skip()` when conditions aren't met

Example of WRONG vs CORRECT:

```python
# ❌ WRONG - arbitrary sleep
time.sleep(0.5)
assert response.headers.get("X-Cache") == "MISS"

# ✅ CORRECT - use polling helper
wait_for_cache_miss(url)

# ✅ CORRECT - retry with condition
for _ in range(12):
    time.sleep(0.5)  # Small delay between retries is OK
    if response.headers.get("X-Cache") == "MISS":
        break
else:
    pytest.skip("Cache state not reached - may be timing issue")

---

## Release Process

### Version Locations (all three must match)

1. `varnish-http-purge.php` line 6: `Version: X.Y.Z`
2. `varnish-http-purge.php` class constant: `public static $version = 'X.Y.Z';`
3. `readme.txt` line 6: `Stable tag: X.Y.Z`

### Changelog

Add entry at top of `changelog.txt`:
```
= X.Y.Z =
* Month Year
* Fix/New: Description
```

### Validation & Release

```bash
make validate    # Checks version consistency across all files
make lint        # PHPCS + PHPStan
make tests       # Full test suite
git commit ...
git tag X.Y.Z
git push origin trunk && git push origin X.Y.Z
```

Pushing a tag triggers `.github/workflows/deploy.yml` which:
1. Runs the full test suite
2. Deploys to WordPress.org SVN via `10up/action-wordpress-plugin-deploy`
3. Creates a GitHub release with a `.zip` artifact
