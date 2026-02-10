.DEFAULT_GOAL := help

.PHONY: help
help: ## Show available targets
	@awk 'BEGIN{FS=":.*##";print "\nUsage: make <target>\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ===================================
# DDEV Environment
# ===================================

.PHONY: up
up: ## Start DDEV, install all TYPO3 versions, render docs, generate landing page
	ddev start
	ddev install-all
	ddev docs
	ddev generate-index
	@echo ""
	@echo "All ready! Visit: https://nr-passkeys-be.ddev.site/"

.PHONY: start
start: ## Start DDEV environment
	ddev start

.PHONY: stop
stop: ## Stop DDEV environment
	ddev stop

.PHONY: install-v13
install-v13: ## Install TYPO3 13.4 LTS with extension
	ddev install-v13

.PHONY: install-v14
install-v14: ## Install TYPO3 14.x with extension
	ddev install-v14

.PHONY: install-all
install-all: ## Install all TYPO3 versions
	ddev install-all

.PHONY: ssh
ssh: ## SSH into DDEV web container
	ddev ssh

.PHONY: docs
docs: ## Render TYPO3 extension documentation
	ddev docs

.PHONY: urls
urls: ## Show all access URLs
	@echo ""
	@echo "nr_passkeys_be - Access URLs"
	@echo "==============================="
	@echo ""
	@echo "Landing page:"
	@echo "  https://nr-passkeys-be.ddev.site/"
	@echo ""
	@echo "TYPO3 13.4 LTS:"
	@echo "  Frontend: https://v13.nr-passkeys-be.ddev.site/"
	@echo "  Backend:  https://v13.nr-passkeys-be.ddev.site/typo3/"
	@echo ""
	@echo "TYPO3 14.x:"
	@echo "  Frontend: https://v14.nr-passkeys-be.ddev.site/"
	@echo "  Backend:  https://v14.nr-passkeys-be.ddev.site/typo3/"
	@echo ""
	@echo "Documentation:"
	@echo "  https://docs.nr-passkeys-be.ddev.site/"
	@echo ""
	@echo "Backend Credentials:"
	@echo "  Username: admin"
	@echo "  Password: Joh316!!"
	@echo ""

# ===================================
# Code Quality
# ===================================

.PHONY: install
install: ## Install composer dependencies
	composer install

.PHONY: lint
lint: ## Check code style (PER-CS2.0)
	composer ci:lint:php

.PHONY: format
format: ## Auto-fix code style issues
	composer ci:lint:php:fix

.PHONY: stan
stan: ## Run PHPStan static analysis (level 8)
	composer ci:stan

.PHONY: test
test: test-unit ## Run unit tests

.PHONY: test-unit
test-unit: ## Run unit tests
	composer ci:test:php:unit

.PHONY: test-functional
test-functional: ## Run functional tests (requires MySQL)
	composer ci:test:php:functional

.PHONY: test-fuzz
test-fuzz: ## Run fuzz tests
	.Build/bin/phpunit -c Build/phpunit.xml --testsuite fuzz --no-coverage

.PHONY: test-js
test-js: ## Run JavaScript unit tests (vitest)
	npm run test:js

.PHONY: test-js-coverage
test-js-coverage: ## Run JavaScript unit tests with coverage
	npm run test:js:coverage

.PHONY: test-e2e
test-e2e: ## Run E2E tests (requires DDEV running)
	npm run test:e2e

.PHONY: test-e2e-headed
test-e2e-headed: ## Run E2E tests in headed browser
	npm run test:e2e:headed

.PHONY: mutation
mutation: ## Run mutation tests (Infection)
	composer ci:mutation

.PHONY: ci
ci: lint stan test-unit test-fuzz test-js ## Run all local CI checks (no DB required)

.PHONY: clean
clean: ## Clean temporary files and caches
	rm -rf .php-cs-fixer.cache var/ infection.log infection-summary.log .phpunit.result.cache coverage/ test-results/ playwright-report/
