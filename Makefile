SHELL := /bin/bash
COMPOSE ?= docker compose

# Run containers as the host user so files created in the bind mount stay owned by you.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

RUN := $(COMPOSE) run --rm --no-deps -T app

.DEFAULT_GOAL := help
.PHONY: help setup secrets up down logs shell psql test verify

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
	@echo "Service: http://127.0.0.1:$$(grep -E '^APP_PORT=' .env | cut -d= -f2)/up"

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

test: ## Run the test suite on the separate test database (ARGS="--filter Name")
	$(COMPOSE) up -d --wait db-test
	$(RUN) sh -c 'php artisan config:clear --no-interaction >/dev/null && php artisan test $(ARGS)'

verify: ## Validate composer files, check code style and run the test suite
	$(RUN) composer validate --strict
	$(RUN) vendor/bin/pint --test
	@$(MAKE) --no-print-directory test
