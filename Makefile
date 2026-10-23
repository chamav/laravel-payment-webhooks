SHELL := /bin/bash
COMPOSE ?= docker compose

# Run containers as the host user so files created in the bind mount stay owned by you.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

RUN := $(COMPOSE) run --rm --no-deps -T app

.DEFAULT_GOAL := help
.PHONY: help setup secrets up down logs shell psql test verify reset-demo migrate-test demo

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-10s %s\n", $$1, $$2}'

setup: ## Create .env and secrets, build, install dependencies, migrate and start the service
	@test -f .env || { cp .env.example .env; echo "Created .env from .env.example"; }
	@$(MAKE) --no-print-directory secrets
	$(COMPOSE) build app
	$(RUN) composer install --no-interaction --prefer-dist
	@grep -qE '^APP_KEY=.+' .env || $(RUN) php artisan key:generate --no-interaction
	$(COMPOSE) up -d --wait db
	$(RUN) php artisan migrate --force --no-interaction
	$(COMPOSE) up -d --wait app
	@echo "Service: http://127.0.0.1:$${APP_PORT:-$$(grep -E '^APP_PORT=' .env | cut -d= -f2)}/up"

# Fills empty secrets in .env with 32 random bytes (64 hex chars); existing values are kept.
secrets:
	@for var in DB_PASSWORD WEBHOOK_SECRET; do \
		grep -qE "^$$var=" .env || echo "$$var=" >> .env; \
		if grep -qE "^$$var=$$" .env; then \
			value=$$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n'); \
			sed -i.bak "s/^$$var=$$/$$var=$$value/" .env && rm -f .env.bak; \
			echo "Generated $$var in .env"; \
		fi; \
	done

demo: ## Send signed HTTP requests and check fresh demo orders
	$(COMPOSE) up -d --wait app
	$(RUN) php artisan demo:run

up: ## Start the service
	$(COMPOSE) up -d --wait app

down: ## Stop project containers, keep data
	$(COMPOSE) down

logs: ## Follow application logs
	$(COMPOSE) logs -f app

shell: ## Open a shell in the application container
	$(COMPOSE) exec app bash

psql: ## Open psql on the demo database
	$(COMPOSE) exec db sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'

reset-demo: ## Delete only orders, payments and receipts from the local demo database
	$(RUN) php artisan demo:reset --no-interaction

migrate-test: ## Migrate only the separate test database
	$(COMPOSE) up -d --wait db-test
	$(COMPOSE) run --rm --no-deps -T -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=db-test -e DB_DATABASE=webhooks_test -e DB_USERNAME=webhooks_test -e DB_PASSWORD=webhooks_test app sh -c 'php artisan config:clear --no-interaction >/dev/null && php artisan migrate --force --no-interaction'

test: ## Run the test suite on the separate test database (ARGS="--filter Name")
	$(COMPOSE) up -d --wait db-test
	$(RUN) sh -c 'php artisan config:clear --no-interaction >/dev/null && php artisan test $(ARGS)'

verify: ## Validate Composer, run Pint, PHPStan level 8 and the test suite
	$(RUN) composer validate --strict
	$(RUN) vendor/bin/pint --test
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=512M
	@$(MAKE) --no-print-directory test
