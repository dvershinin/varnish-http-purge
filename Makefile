SHELL := /bin/bash

.PHONY: up down build setup test tests logs clean pytest teststack lint phpcs phpcbf phpstan

# ============================================================================
# Linting and Static Analysis
# ============================================================================

lint: phpcs phpstan
	@echo "All linting checks passed!"

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

check-txt:
	@echo "Checking for escaped quotes in text files..."
	@if grep -r '\\\"' *.txt 2>/dev/null; then \
		echo "ERROR: Found escaped quotes in text files!"; exit 1; \
	else \
		echo "No escaped quotes found in text files."; \
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
	bash tests/setup.sh

test:
	# Legacy shell test kept for compatibility; prefer `make tests`
	@echo "Use 'make tests' (pytest) instead."

tests:
	# Ensure stack is up and WP is initialized before tests
	export TEST_PORT=$$(python3 -c 'import socket; s=socket.socket(); s.bind(("", 0)); print(s.getsockname()[1]); s.close()') && \
	$(MAKE) -e up && \
	cd tests && bash setup.sh && \
	docker compose run --rm tester -q

pytest:
	cd tests && docker compose run --rm tester -q

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


