# ──────────────────────────────────────────────
# Trakli – project-specific hook variables
# ──────────────────────────────────────────────

DEFAULT_DB_PORT      ?= 3306
DEFAULT_TEST_DB_PORT ?= 3307
DEFAULT_PMA_PORT     ?= 8080
DEFAULT_SMARTQL_PORT ?= 5000

EXTRA_PORTS_SCRIPT = \
	DB_PORT=$$(port=$(DEFAULT_DB_PORT); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port); \
	TEST_DB_PORT=$$(port=$(DEFAULT_TEST_DB_PORT); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port); \
	PMA_PORT=$$(port=$(DEFAULT_PMA_PORT); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port); \
	SMARTQL_PORT=$$(port=$(DEFAULT_SMARTQL_PORT); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port); \
	echo "FORWARD_DB_PORT=$$DB_PORT" >> $(PORTS_FILE); \
	echo "FORWARD_TEST_DB_PORT=$$TEST_DB_PORT" >> $(PORTS_FILE); \
	echo "PMA_PORT=$$PMA_PORT" >> $(PORTS_FILE); \
	echo "SMARTQL_PORT=$$SMARTQL_PORT" >> $(PORTS_FILE);

EXTRA_UP_INFO = \
	echo "  phpMyAdmin: http://localhost:$$PMA_PORT" && \
	echo "  SmartQL:    http://localhost:$$SMARTQL_PORT" && \
	echo "  MySQL:      localhost:$$FORWARD_DB_PORT"

TEST_CMD = php artisan test --coverage
LINT_CMD = sh -c "composer phpcs:test && composer phpmd && composer pint:test && composer openapi:test"

# ──────────────────────────────────────────────
# Include shared targets from laravel.mk
# ──────────────────────────────────────────────
include laravel.mk

# ──────────────────────────────────────────────
# Trakli-specific targets
# ──────────────────────────────────────────────

.PHONY: phpmd phpstan format format-fix openapi openapi-test ci prod-build prod-push prod-up prod-down

ci: lint test ## Run every check that CI runs (lint + tests with coverage)

phpmd: ## Run mess detector
	$(EXEC) composer phpmd

phpstan: ## Run static analyzer
	$(EXEC) composer phpstan

format: ## Check code style (phpcs)
	$(EXEC) composer phpcs:test

format-fix: ## Fix code style (phpcs)
	$(EXEC) composer phpcs

openapi: ## Generate OpenAPI documentation
	$(EXEC) composer openapi

openapi-test: ## Test OpenAPI documentation
	$(EXEC) composer openapi:test

prod-build: ## Build the production image
	docker build -f docker/prod/Dockerfile -t ghcr.io/trakli/webservice:latest .

prod-push: ## Push production image to registry
	docker push ghcr.io/trakli/webservice:latest

prod-up: ## Start the production container locally
	docker compose -f docker-compose.prod.yml up -d

prod-down: ## Stop the production container
	docker compose -f docker-compose.prod.yml down
