.DEFAULT_GOAL := help

.PHONY: setup up down stop restart composer-install composer-update clean test lint lint-fix openapi openapi-test fix-permissions migrate migrate-fresh seed migrate-fresh-seed optimize tinker bash logs help prod-build prod-up prod-down


# Get current user's UID and GID
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

setup: ## Build and start the application
	@echo "Building custom image with HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID)"
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose build app
	@echo "Starting containers..."
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose up -d
	@echo "Waiting for containers to be ready..."
	@sleep 5
	@echo "Installing composer dependencies..."
	$(MAKE) composer-install
	@echo "Generating application key..."
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user www-data app php artisan key:generate
	@echo "Running fresh migrations with seeders..."
	$(MAKE) migrate-fresh-seed
	@echo "Setup complete! Application ready at http://localhost:8000"

up: ## Start the application
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose up -d

down: ## Stop and remove the application containers
	docker compose down

stop: ## Stop the application containers
	docker compose stop

restart: ## Restart the application
	$(MAKE) down
	$(MAKE) up

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
	docker compose -f docker-compose.prod.yml build

prod-up: ## Start the production container locally
	docker compose -f docker-compose.prod.yml up -d

prod-down: ## Stop and remove the production container
	docker compose -f docker-compose.prod.yml down

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@egrep '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
