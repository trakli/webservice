.DEFAULT_GOAL := help

.PHONY: setup up down stop restart reset-ports composer-install composer-update clean test lint lint-fix openapi openapi-test fix-permissions migrate migrate-fresh seed migrate-fresh-seed optimize tinker bash logs help prod-build prod-up prod-down ports

# Get current user's UID and GID
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

# Port configuration file
PORTS_FILE := .ports

# Default ports
DEFAULT_APP_PORT := 8000
DEFAULT_DB_PORT := 3306
DEFAULT_PMA_PORT := 8080
DEFAULT_MAILHOG_SMTP_PORT := 1025
DEFAULT_MAILHOG_UI_PORT := 8025
DEFAULT_SMARTQL_PORT := 5000

# Function to find available port starting from a given port
define find_port
$(shell port=$(1); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port)
endef

# Generate .ports file with available ports
.ports:
	@echo "Finding available ports..."
	@echo "# Auto-generated port assignments - do not edit" > $(PORTS_FILE)
	@echo "# Generated at: $$(date)" >> $(PORTS_FILE)
	@APP_PORT=$(call find_port,$(DEFAULT_APP_PORT)); \
	DB_PORT=$(call find_port,$(DEFAULT_DB_PORT)); \
	PMA_PORT=$(call find_port,$(DEFAULT_PMA_PORT)); \
	MAILHOG_SMTP_PORT=$(call find_port,$(DEFAULT_MAILHOG_SMTP_PORT)); \
	MAILHOG_UI_PORT=$(call find_port,$(DEFAULT_MAILHOG_UI_PORT)); \
	SMARTQL_PORT=$(call find_port,$(DEFAULT_SMARTQL_PORT)); \
	echo "APP_PORT=$$APP_PORT" >> $(PORTS_FILE); \
	echo "FORWARD_DB_PORT=$$DB_PORT" >> $(PORTS_FILE); \
	echo "PMA_PORT=$$PMA_PORT" >> $(PORTS_FILE); \
	echo "MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT" >> $(PORTS_FILE); \
	echo "MAILHOG_UI_PORT=$$MAILHOG_UI_PORT" >> $(PORTS_FILE); \
	echo "SMARTQL_PORT=$$SMARTQL_PORT" >> $(PORTS_FILE)
	@echo "Port assignments saved to $(PORTS_FILE)"

ports: ## Show current port assignments
	@if [ -f $(PORTS_FILE) ]; then \
		echo "Current port assignments:"; \
		cat $(PORTS_FILE) | grep -v "^#"; \
	else \
		echo "No ports file found. Run 'make up' to generate."; \
	fi

setup: .ports ## Build and start the application
	@echo "Building custom image with HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID)"
	@. ./$(PORTS_FILE) && HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) \
		APP_PORT=$$APP_PORT FORWARD_DB_PORT=$$FORWARD_DB_PORT PMA_PORT=$$PMA_PORT \
		MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT MAILHOG_UI_PORT=$$MAILHOG_UI_PORT \
		SMARTQL_PORT=$$SMARTQL_PORT docker compose build app
	@echo "Starting containers..."
	@. ./$(PORTS_FILE) && HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) \
		APP_PORT=$$APP_PORT FORWARD_DB_PORT=$$FORWARD_DB_PORT PMA_PORT=$$PMA_PORT \
		MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT MAILHOG_UI_PORT=$$MAILHOG_UI_PORT \
		SMARTQL_PORT=$$SMARTQL_PORT docker compose up -d
	@echo "Waiting for containers to be ready..."
	@sleep 5
	@echo "Installing composer dependencies..."
	$(MAKE) composer-install
	@echo "Generating application key..."
	@. ./$(PORTS_FILE) && HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) \
		APP_PORT=$$APP_PORT FORWARD_DB_PORT=$$FORWARD_DB_PORT PMA_PORT=$$PMA_PORT \
		MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT MAILHOG_UI_PORT=$$MAILHOG_UI_PORT \
		SMARTQL_PORT=$$SMARTQL_PORT docker compose exec --user www-data app php artisan key:generate
	@echo "Running fresh migrations with seeders..."
	$(MAKE) migrate-fresh-seed
	@. ./$(PORTS_FILE) && echo "Setup complete! Application ready at http://localhost:$$APP_PORT"

up: .ports ## Start the application
	@. ./$(PORTS_FILE) && HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) \
		APP_PORT=$$APP_PORT FORWARD_DB_PORT=$$FORWARD_DB_PORT PMA_PORT=$$PMA_PORT \
		MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT MAILHOG_UI_PORT=$$MAILHOG_UI_PORT \
		SMARTQL_PORT=$$SMARTQL_PORT docker compose up -d
	@. ./$(PORTS_FILE) && echo "Services running on:" && \
		echo "  App:        http://localhost:$$APP_PORT" && \
		echo "  phpMyAdmin: http://localhost:$$PMA_PORT" && \
		echo "  MailHog:    http://localhost:$$MAILHOG_UI_PORT" && \
		echo "  SmartQL:    http://localhost:$$SMARTQL_PORT" && \
		echo "  MySQL:      localhost:$$FORWARD_DB_PORT"

down: ## Stop and remove the application containers
	docker compose down

stop: ## Stop the application containers
	docker compose stop

restart: ## Restart the application
	$(MAKE) down
	$(MAKE) up

reset-ports: ## Clear port assignments and find new available ports
	@rm -f $(PORTS_FILE)
	@$(MAKE) .ports

composer-install: ## Install composer dependencies
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer install

composer-update: ## Update composer dependencies
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer update

test: ## Run tests
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec -T --user www-data app php artisan test

lint: ## Lint the code
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer pint:test

lint-fix: ## Fix linting errors
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer pint

openapi: ## Generate OpenAPI documentation
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer openapi

openapi-test: ## Test OpenAPI documentation
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app composer openapi:test

clean: ## Stop and remove all containers, networks, and volumes
	docker compose down --rmi all -v
	@rm -f $(PORTS_FILE)

migrate: ## Run database migrations
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan migrate

migrate-fresh: ## Drop all tables and re-run all migrations
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan migrate:fresh

seed: ## Seed the database
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan db:seed

migrate-fresh-seed: ## Run fresh migrations with seeders
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan migrate:fresh --seed

optimize: ## Optimize the application
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan optimize

tinker: ## Start a tinker session
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan tinker

bash: ## Start a bash session in the app container
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app bash

fix-permissions: ## Fix file permissions
	docker compose exec app bash -c "chown -R www-data:www-data /var/www/html && chmod -R 775 /var/www/html/storage && chmod -R 775 /var/www/html/bootstrap/cache"

logs: ## Show application logs
	docker compose logs -f app

prod-build: ## Build the production image
	docker build -f docker/prod/Dockerfile -t ghcr.io/trakli/webservice:latest .

prod-push: ## Push production image to registry (requires docker login)
	docker push ghcr.io/trakli/webservice:latest

prod-up: ## Start the production container locally
	docker compose -f docker-compose.prod.yml up -d

prod-down: ## Stop and remove the production container
	docker compose -f docker-compose.prod.yml down

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@egrep '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
