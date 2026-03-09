# FideX PHP Reference Implementation — Makefile
# Usage: make <target>

PHP     ?= php
PHPUNIT ?= vendor/bin/phpunit
PHPSTAN ?= vendor/bin/phpstan

.PHONY: help install install-webapp keys migrate test test-unit test-integration test-crypto stan serve worker build-webapp clean

# ─── Default ─────────────────────────────────────────────────────────────────
help:
	@echo "FideX PHP Reference Implementation"
	@echo ""
	@echo "Setup:"
	@echo "  make install        Install Composer dependencies"
	@echo "  make install-webapp Install Node.js (TypeScript) devDependencies"
	@echo "  make keys           Generate RSA-4096 key pairs"
	@echo "  make migrate        Create/update database tables"
	@echo ""
	@echo "Development:"
	@echo "  make serve          Start PHP dev server on :8080 (webapp at /onboarding/)"
	@echo "  make worker         Run queue worker once"
	@echo "  make test           Run all tests"
	@echo "  make test-unit      Run unit tests only (no crypto)"
	@echo "  make test-integration Run integration tests (SQLite :memory:)"
	@echo "  make test-crypto    Run crypto round-trip tests"
	@echo "  make stan           Run PHPStan static analysis"
	@echo "  make build-webapp   Compile TypeScript webapp → public/onboarding/app.js"
	@echo ""
	@echo "Maintenance:"
	@echo "  make clean          Remove generated files (not keys/db)"

# ─── Setup ───────────────────────────────────────────────────────────────────
install:
	composer install --prefer-dist --no-interaction

keys:
	$(PHP) bin/generate-keys.php

migrate:
	$(PHP) bin/migrate.php

setup: install keys migrate
	@echo "✓ FideX PHP node is ready. Edit .env and run: make serve"
	@echo "  Onboarding UI available at: http://localhost:8080/onboarding/"

# ─── Development ─────────────────────────────────────────────────────────────
serve:
	@echo "Starting FideX dev server at http://localhost:8080"
	$(PHP) -S 0.0.0.0:8080 -t public/

worker:
	$(PHP) bin/worker.php

worker-loop:
	@echo "Running queue worker in a loop (Ctrl+C to stop)..."
	@while true; do $(PHP) bin/worker.php; sleep 5; done

# ─── Testing ─────────────────────────────────────────────────────────────────
test:
	$(PHPUNIT) --colors=always

test-unit:
	$(PHPUNIT) --testsuite Unit --colors=always

test-crypto:
	$(PHPUNIT) tests/Unit/Adapter/Crypto/ --colors=always

test-integration:
	$(PHPUNIT) --testsuite Integration --colors=always

test-use-cases:
	$(PHPUNIT) tests/Unit/Core/UseCase/ --colors=always

test-coverage:
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-text --colors=always

# ─── Static Analysis ─────────────────────────────────────────────────────────
stan:
	$(PHPSTAN) analyse src tests --level 5

# ─── Webapp (TypeScript) ──────────────────────────────────────────────────────
install-webapp:
	npm install

build-webapp: install-webapp
	npm run build
	@echo "✓ Webapp compiled → public/onboarding/app.js"

# ─── Maintenance ─────────────────────────────────────────────────────────────
clean:
	rm -rf .phpunit.cache .phpunit.result.cache .phpstan.result.cache

# ─── Docker (for local dev on Linux/Mac) ─────────────────────────────────────
up:
	docker compose up -d

down:
	docker compose down

docker-test:
	docker compose run --rm app make test

docker-migrate:
	docker compose run --rm app make migrate
