.PHONY: setup up down restart composer-install composer-update clean test lint lint-fix openapi openapi-test fix-permissions migrate migrate-fresh seed migrate-fresh-seed optimize tinker bash logs

# Get current user's UID and GID
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)

setup:
	@echo "Building custom image with HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID)"
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose build app
	@echo "Starting containers..."
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose up -d
	@echo "Waiting for containers to be ready..."
	@sleep 5
	@echo "Installing composer dependencies..."
	$(MAKE) composer-install
	@echo "Generating application key..."
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan key:generate
	@echo "Running fresh migrations with seeders..."
	$(MAKE) migrate-fresh-seed
	@echo "Setup complete! Application ready at http://localhost:8000"

up:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose up -d

down:
	docker compose down

restart:
	$(MAKE) down
	$(MAKE) up

composer-install:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer install

composer-update:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer update

test:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec -T --user trakli app php artisan test

lint:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer pint:test

lint-fix:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer pint

openapi:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer openapi

openapi-test:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app composer openapi:test

clean:
	docker compose down --rmi all -v

migrate:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan migrate

migrate-fresh:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan migrate:fresh

seed:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan db:seed

migrate-fresh-seed:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan migrate:fresh --seed

optimize:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan optimize

tinker:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app php artisan tinker

bash:
	HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID) docker compose exec --user trakli app bash

fix-permissions:
	docker compose exec app bash -c "chown -R trakli:www-data /var/www/html && chmod -R 775 /var/www/html/storage && chmod -R 775 /var/www/html/bootstrap/cache"

logs:
	docker compose logs -f app

