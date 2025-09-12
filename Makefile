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

logs:
	cd tests && docker compose logs -f | cat

clean: down
	cd tests && docker volume rm $$(docker volume ls -q | grep -E '(wp_data|db_data)') || true


