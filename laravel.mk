.DEFAULT_GOAL := help

HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

DEFAULT_APP_PORT ?= 8000
DEFAULT_MAILHOG_SMTP_PORT ?= 1025
DEFAULT_MAILHOG_UI_PORT ?= 8025
PORTS_FILE := .ports

define find_port
$(shell port=$(1); while nc -z 127.0.0.1 $$port 2>/dev/null || lsof -i :$$port >/dev/null 2>&1; do port=$$((port + 1)); done; echo $$port)
endef

# Projects can define EXTRA_PORTS_SCRIPT to add additional port assignments.
# Example: EXTRA_PORTS_SCRIPT = DB_PORT=$$(call find_port,3306); echo "DB_PORT=$$DB_PORT" >> $(PORTS_FILE);
EXTRA_PORTS_SCRIPT ?=

# Projects can define EXTRA_UP_INFO to show additional service URLs after 'make up'.
# Example: EXTRA_UP_INFO = echo "  MySQL: localhost:$$FORWARD_DB_PORT"
EXTRA_UP_INFO ?=

# Projects can override TEST_CMD and LINT_CMD for custom test/lint behavior.
TEST_CMD ?= php artisan test
LINT_CMD ?= composer pint:test

.ports:
	@echo "Finding available ports..."
	@echo "# Auto-generated port assignments" > $(PORTS_FILE)
	@APP_PORT=$(call find_port,$(DEFAULT_APP_PORT)); \
	MAILHOG_SMTP_PORT=$(call find_port,$(DEFAULT_MAILHOG_SMTP_PORT)); \
	MAILHOG_UI_PORT=$(call find_port,$(DEFAULT_MAILHOG_UI_PORT)); \
	echo "APP_PORT=$$APP_PORT" >> $(PORTS_FILE); \
	echo "MAILHOG_SMTP_PORT=$$MAILHOG_SMTP_PORT" >> $(PORTS_FILE); \
	echo "MAILHOG_UI_PORT=$$MAILHOG_UI_PORT" >> $(PORTS_FILE); \
	$(EXTRA_PORTS_SCRIPT)
	@echo "Port assignments saved to $(PORTS_FILE)"

DC = $(shell if [ -f $(PORTS_FILE) ]; then grep -v "^\#" $(PORTS_FILE) | xargs; fi) HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose
EXEC = $(DC) exec --user www-data app

up: .ports ## Start the application
	@. ./$(PORTS_FILE) && $(DC) up -d
	@. ./$(PORTS_FILE) && echo "Services running on:" && \
		echo "  App:      http://localhost:$$APP_PORT" && \
		echo "  MailHog:  http://localhost:$$MAILHOG_UI_PORT" && \
		$(if $(EXTRA_UP_INFO),$(EXTRA_UP_INFO),:)

setup: up composer-install ## First-time setup
	$(EXEC) php artisan key:generate
	$(MAKE) migrate-fresh-seed
	@echo "Setup complete!"

restart: ## Restart the application
	$(MAKE) down
	$(MAKE) up

reset-ports: ## Clear ports and find new available ones
	@rm -f $(PORTS_FILE)
	@$(MAKE) .ports

ports: ## Show current port assignments
	@if [ -f $(PORTS_FILE) ]; then \
		cat $(PORTS_FILE) | grep -v "^#"; \
	else \
		echo "No ports file. Run 'make up' to generate."; \
	fi

composer-install: ## Install composer dependencies
	$(EXEC) composer install

composer-update: ## Update composer dependencies
	$(EXEC) composer update

test: ## Run tests
	$(EXEC) $(TEST_CMD)

lint: ## Check code formatting
	$(EXEC) $(LINT_CMD)

lint-fix: ## Fix code formatting
	$(EXEC) composer pint

migrate: ## Run database migrations
	$(EXEC) php artisan migrate

migrate-fresh: ## Drop all tables and re-run all migrations
	$(EXEC) php artisan migrate:fresh

seed: ## Seed the database
	$(EXEC) php artisan db:seed

migrate-fresh-seed: ## Run fresh migrations with seeders
	$(EXEC) php artisan migrate:fresh --seed

optimize: ## Optimize the application
	$(EXEC) php artisan optimize

tinker: ## Start a tinker session
	$(EXEC) php artisan tinker

bash: ## Open a bash session in the app container
	$(EXEC) bash

fix-permissions: ## Fix file permissions
	docker compose exec app bash -c "chown -R www-data:www-data /var/www/html && chmod -R 775 /var/www/html/storage && chmod -R 775 /var/www/html/bootstrap/cache"

logs: ## Show application logs
	docker compose logs -f app

down: ## Stop and remove containers
	docker compose down

stop: ## Stop containers
	docker compose stop

clean: ## Remove all containers, networks, images, and volumes
	docker compose down --rmi all -v
	@rm -f $(PORTS_FILE)

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@egrep '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sed 's/^[^:]*://' | sort -u | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
