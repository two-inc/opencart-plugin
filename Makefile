# ==============================================================================
# Two for OpenCart 4: development environment and checks
# ==============================================================================

# Local settings live in .env only (copy .env.example). Values there override
# the defaults below, and docker compose reads the same file.
-include .env

COMPOSE      := docker compose
CONTAINER    := two-opencart
PORT         ?= 1238
URL          := http://localhost:$(PORT)/
ADMIN_USER   ?= admin
ADMIN_EMAIL  ?= exampleuser@two.inc
ADMIN_PASSWD ?= examplepassword123
export PORT ADMIN_USER ADMIN_EMAIL ADMIN_PASSWD

# Internal Two developers (an @two.inc gcloud account) point at staging,
# everyone else at sandbox. TWO_INTERNAL_ENVIRONMENTS offers the staging
# environment in the admin, so it follows the same split.
TWO_ENV                   := $(shell gcloud config get-value account 2>/dev/null | grep -q '@two\.inc$$' && echo staging || echo sandbox)
TWO_ENVIRONMENT           ?= $(TWO_ENV)
TWO_INTERNAL_ENVIRONMENTS ?= $(if $(filter staging,$(TWO_ENV)),1,)
TWO_API_KEY               ?=
export TWO_ENVIRONMENT TWO_INTERNAL_ENVIRONMENTS

# Static analysis pins. Re-derive a checksum from the upstream release asset
# when bumping a version, never copy one from elsewhere.
PHPSTAN_VERSION       := 2.2.5
PHPSTAN_SHA256        := 1b2f03384ebcfd67053b06b69cbc0b9f62bf239349f69eaf723649409789e2e6
# The supported floor: PHPStan checks the adapter against these sources.
OPENCART_FLOOR        := 4.1.0.0
OPENCART_FLOOR_SHA256 := 5d9883de4e37852dbb71c83b8833b9aa4419bc3e71d808e62e38522b2fd1b294
CACHE                 := .cache

.PHONY: help install configure run stop clean logs test lint phpstan archive patch minor major

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-12s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Build and start a fresh OpenCart shop with the extension installed and enabled
install: clean
	$(COMPOSE) up -d --build
	@echo "Waiting for the OpenCart install (cli_install.php)..."
	@for i in $$(seq 1 90); do \
		docker exec $(CONTAINER) grep -q "define('DB_DATABASE'" /var/www/html/config.php 2>/dev/null && exit 0; \
		sleep 2; \
	done; echo "OpenCart did not install, see: make logs"; exit 1
	$(MAKE) configure
	@echo ""
	@echo "========================================="
	@echo " OpenCart store: $(URL)"
	@echo " Admin panel:    $(URL)admin/"
	@echo " Credentials:    $(ADMIN_USER) / $(ADMIN_PASSWD)"
	@echo " Two settings:   Extensions > Extensions > Payments > Two"
	@echo "========================================="

## Install and enable the extension in the running shop (idempotent): make configure TWO_API_KEY=xxx
configure:
	docker exec -u www-data \
		-e OPENCART_DIR=/var/www/html \
		-e TWO_ENVIRONMENT=$(TWO_ENVIRONMENT) \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		$(CONTAINER) php /var/www/html/extension/two/dev/configure.php

## Start the existing shop containers
run:
	$(COMPOSE) start
	@echo " OpenCart store: $(URL)"

## Stop the shop containers
stop:
	$(COMPOSE) stop

## Remove the shop containers and their volumes
clean:
	$(COMPOSE) down -v --remove-orphans

## Follow the shop logs
logs:
	$(COMPOSE) logs -f

## Run the offline PHP test suite
test:
	php tests/run.php

## Syntax-check every PHP file
lint:
	@git ls-files -z '*.php' | xargs -0 -n1 php -l > /dev/null && echo "lint: ok"

## Run PHPStan against the OpenCart floor sources (downloads both, pinned)
phpstan: $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar
	dev/ci/fetch-opencart.sh $(OPENCART_FLOOR) $(OPENCART_FLOOR_SHA256) $(CACHE)/opencart
	php $(CACHE)/phpstan-$(PHPSTAN_VERSION).phar analyse --configuration=phpstan.neon --no-progress --memory-limit=1G

$(CACHE)/phpstan-$(PHPSTAN_VERSION).phar:
	@mkdir -p $(CACHE)
	curl -fsSL -o $@.tmp "https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar"
	echo "$(PHPSTAN_SHA256)  $@.tmp" | sha256sum -c --quiet -
	mv $@.tmp $@

## Build dist/two.ocmod.zip from HEAD
archive:
	dev/package.sh

## Bump the patch version (install.json), commit, no tag or push
patch:
	bumpver update --patch

## Bump the minor version
minor:
	bumpver update --minor

## Bump the major version
major:
	bumpver update --major
