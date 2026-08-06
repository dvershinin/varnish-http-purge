SHELL := /bin/bash

.PHONY: up down build setup test tests logs clean pytest teststack lint phpcs phpcbf phpstan phpstan-phpdoc php-compat validate security check-all plugin-check

# ============================================================================
# Linting and Static Analysis
# ============================================================================

lint: phpcs phpstan phpstan-phpdoc
	@echo "All linting checks passed!"

# Run ALL checks (like CI does)
check-all: lint php-compat validate security check-txt
	@echo ""
	@echo "🎉 ALL CHECKS PASSED!"

phpcs:
	@echo "Running PHP CodeSniffer..."
	@if [ -x "$$HOME/.composer/vendor/bin/phpcs" ]; then \
		$$HOME/.composer/vendor/bin/phpcs; \
	elif command -v phpcs &> /dev/null; then \
		phpcs; \
	else \
		echo "phpcs not installed. Install with:"; \
		echo "  composer global require wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer"; \
	fi

phpcbf:
	@echo "Auto-fixing PHP CodeSniffer issues..."
	@if [ -x "$$HOME/.composer/vendor/bin/phpcbf" ]; then \
		$$HOME/.composer/vendor/bin/phpcbf || true; \
	elif command -v phpcbf &> /dev/null; then \
		phpcbf || true; \
	else \
		echo "phpcbf not installed. Install with:"; \
		echo "  composer global require wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer"; \
	fi

phpstan:
	@echo "Running PHPStan..."
	@if command -v phpstan &> /dev/null; then \
		phpstan analyse --no-progress --memory-limit=512M; \
	else \
		echo "phpstan not installed. Install with: composer global require phpstan/phpstan"; \
	fi

# PHPDoc-type linter: catches @param/@return types that disagree with reality
# (e.g. `@param array $url` on a method whose callers pass a string). Loads the
# WordPress function stubs and runs at level 5 so the mismatch becomes an error.
# See phpstan-phpdoc.neon for the rationale and scope.
phpstan-phpdoc:
	@echo "Running PHPStan PHPDoc-type linter..."
	@if command -v phpstan &> /dev/null; then \
		stub="$$(composer global config home 2>/dev/null)/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php"; \
		if [ ! -f "$$stub" ]; then \
			echo "WordPress stubs missing. Install with: composer global require php-stubs/wordpress-stubs"; \
			exit 1; \
		fi; \
		mkdir -p build && ln -sf "$$stub" build/wordpress-stubs.php; \
		phpstan analyse -c phpstan-phpdoc.neon --no-progress --memory-limit=1G; \
	else \
		echo "phpstan not installed. Install with: composer global require phpstan/phpstan"; \
	fi

check-txt:
	@echo "Checking for escaped quotes in text files..."
	@if grep -r '\\\"' *.txt 2>/dev/null; then \
		echo "ERROR: Found escaped quotes in text files!"; exit 1; \
	else \
		echo "✅ No escaped quotes found in text files."; \
	fi

php-compat:
	@echo "Checking PHP compatibility..."
	@declared=$$(grep -i "^Requires PHP:" readme.txt | sed 's/.*: *//'); \
	echo "Declared minimum PHP: $$declared"; \
	if [ -x "$$HOME/.composer/vendor/bin/phpcs" ]; then \
		$$HOME/.composer/vendor/bin/phpcs --standard=PHPCompatibility \
			--runtime-set testVersion $$declared \
			--extensions=php \
			--ignore=tests/,vendor/,build/ \
			. && echo "✅ Code is compatible with PHP $$declared+"; \
	else \
		echo "PHPCompatibility not installed. Install with:"; \
		echo "  composer global require phpcompatibility/php-compatibility"; \
	fi

validate:
	@echo "Validating plugin headers..."
	@readme_stable=$$(grep -i "^Stable tag:" readme.txt | sed 's/.*: *//'); \
	readme_wp=$$(grep -i "^Requires at least:" readme.txt | sed 's/.*: *//'); \
	readme_php=$$(grep -i "^Requires PHP:" readme.txt | sed 's/.*: *//'); \
	plugin_version=$$(grep -i "^ \* Version:" varnish-http-purge.php | sed 's/.*: *//'); \
	plugin_wp=$$(grep -i "^ \* Requires at least:" varnish-http-purge.php | sed 's/.*: *//'); \
	plugin_php=$$(grep -i "^ \* Requires PHP:" varnish-http-purge.php | sed 's/.*: *//'); \
	errors=0; \
	echo "📦 Stable tag: $$readme_stable vs $$plugin_version"; \
	echo "🐘 PHP version: $$readme_php vs $$plugin_php"; \
	echo "📰 WP version: $$readme_wp vs $$plugin_wp"; \
	if [ "$$readme_stable" != "$$plugin_version" ]; then \
		echo "❌ Version mismatch!"; errors=1; \
	fi; \
	if [ "$$readme_php" != "$$plugin_php" ]; then \
		echo "❌ PHP version mismatch!"; errors=1; \
	fi; \
	if [ "$$readme_wp" != "$$plugin_wp" ]; then \
		echo "❌ WordPress version mismatch!"; errors=1; \
	fi; \
	if [ $$errors -eq 0 ]; then \
		echo "✅ All headers match!"; \
	else \
		exit 1; \
	fi

security:
	@echo "Running security checks..."
	@errors=0; \
	if grep -rn '\beval\s*(' *.php 2>/dev/null; then \
		echo "❌ Found eval() usage!"; errors=1; \
	fi; \
	if grep -rn '\bcreate_function\s*(' *.php 2>/dev/null; then \
		echo "❌ Found create_function()!"; errors=1; \
	fi; \
	if [ $$errors -eq 0 ]; then \
		echo "✅ No critical security issues found"; \
	else \
		exit 1; \
	fi

plugin-check:
	@echo "Running WordPress Plugin Check (requires the test stack to be up)..."
	@cd tests && docker compose run --rm wpcli plugin install plugin-check --activate >/dev/null
	@cd tests && docker compose run --rm wpcli plugin check varnish-http-purge --fields=code,type,message --format=table

changelog:
	@echo "Checking changelog..."
	@stable_tag=$$(grep -i "^Stable tag:" readme.txt | sed 's/.*: *//'); \
	if grep -q "^= $$stable_tag" changelog.txt 2>/dev/null || grep -q "^= $$stable_tag" readme.txt 2>/dev/null; then \
		echo "✅ Changelog entry found for version $$stable_tag"; \
	else \
		echo "❌ No changelog entry for version $$stable_tag"; exit 1; \
	fi

# ============================================================================
# Docker / Testing
# ============================================================================

build:
	cd tests && docker compose build --pull --no-cache

up:
	cd tests && docker compose up -d

down:
	cd tests && docker compose down -v

setup:
	cd tests && bash setup.sh

test:
	# Legacy shell test kept for compatibility; prefer `make tests`
	@echo "Use 'make tests' (pytest) instead."

tests:
	# Ensure stack is up and WP is initialized before tests
	# pytest uses http://varnish:6081 internally - no host port needed
	# Uses -x by default (stop on first failure) for fast iteration
	$(MAKE) up && \
	cd tests && bash setup.sh && \
	docker compose run --rm tester -c tests/pytest.ini -q

pytest:
	# Run pytest with default settings (-x from pytest.ini)
	cd tests && docker compose run --rm tester -c tests/pytest.ini -q

pytest-all:
	# Run ALL tests, don't stop on first failure
	cd tests && docker compose run --rm tester -c tests/pytest.ini -q --no-header -p no:cacheprovider -o "addopts=-ra"

pytest-one:
	# Run a single test file or pattern. Usage: make pytest-one TEST=test_cache_basic
	@if [ -z "$(TEST)" ]; then echo "Usage: make pytest-one TEST=test_name"; exit 1; fi
	cd tests && docker compose run --rm tester -c tests/pytest.ini -v -k "$(TEST)"

pytest-v:
	# Run tests with verbose output (still stops on first failure)
	cd tests && docker compose run --rm tester -c tests/pytest.ini -v

teststack:
	# Use test-local docker-compose.yml under tests
	cd tests && docker compose up -d && \
	bash setup.sh && \
	docker compose run --rm tester -q && \
	docker compose down -v

.PHONY: ci-local
ci-local:
	# Emulate CI workflow locally
	cd tests && docker compose down -v || true
	cd tests && docker compose up -d db wordpress varnish
	# Wait for wordpress health
	bash -lc 'cd tests; for i in {1..120}; do WP=$$(docker inspect -f {{.State.Health.Status}} $$(docker compose ps -q wordpress) || true); echo wordpress=$$WP; if [ "$$WP" = healthy ]; then break; fi; sleep 2; done'
	# Probe varnish from within wordpress
	bash -lc 'cd tests; for i in {1..60}; do if docker compose exec -T wordpress bash -lc "curl -fsS http://varnish/ >/dev/null"; then echo Varnish reachable; break; fi; sleep 2; done'
	cd tests && bash setup.sh
	cd tests && docker compose up --exit-code-from tester --abort-on-container-exit tester | cat

logs:
	cd tests && docker compose logs -f | cat

clean: down
	# Remove any leftover test-related volumes if they exist.
	cd tests && volumes=$$(docker volume ls -q | grep -E '(wp_data|db_data)' || true); \
		if [ -n "$$volumes" ]; then docker volume rm $$volumes; fi

