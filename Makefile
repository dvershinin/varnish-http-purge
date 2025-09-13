SHELL := /bin/bash

.PHONY: up down build setup test tests logs clean pytest teststack

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
	$(MAKE) up
	cd tests && bash setup.sh
	cd tests && docker compose run --rm tester -q

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
	cd tests && docker volume rm $$(docker volume ls -q | grep -E '(wp_data|db_data)') || true


