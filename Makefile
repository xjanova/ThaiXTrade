#############################################
#  TPIX TRADE - Makefile
#  Developed by Xman Studio
#
#  Common commands for development
#############################################

.PHONY: help install dev build deploy test lint clean backup optimize release version

# Default target
help:
	@echo "TPIX TRADE - Available Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make install      Install all dependencies"
	@echo "  make setup-hooks  Install git hooks"
	@echo ""
	@echo "Development:"
	@echo "  make dev          Start development server"
	@echo "  make build        Build for production"
	@echo "  make test         Run tests"
	@echo "  make lint         Run code linting"
	@echo ""
	@echo "Production:"
	@echo "  make deploy       Deploy to production"
	@echo "  make optimize     Optimize for production"
	@echo "  make backup       Create backup"
	@echo "  make rollback     Rollback to previous version"
	@echo ""
	@echo "Maintenance:"
	@echo "  make clean        Clear all caches"
	@echo "  make fix-perms    Fix file permissions"
	@echo "  make audit        Run security audit"
	@echo ""
	@echo "Versioning:"
	@echo "  make version      Show current version"
	@echo "  make bump-patch   Bump patch version (1.0.0 -> 1.0.1)"
	@echo "  make bump-minor   Bump minor version (1.0.0 -> 1.1.0)"
	@echo "  make bump-major   Bump major version (1.0.0 -> 2.0.0)"
	@echo "  make release      Create a new release"

# Installation
install:
	@./install.sh

setup-hooks:
	@./scripts/setup-hooks.sh

# Development
dev:
	@php artisan serve & npm run dev

build:
	@npm run build

test:
	@./scripts/test.sh all

test-php:
	@./scripts/test.sh php

test-js:
	@./scripts/test.sh js

test-watch:
	@npm run test

test-coverage:
	@./scripts/test.sh coverage

lint:
	@./vendor/bin/pint
	@npm run lint 2>/dev/null || true

# Production
deploy:
	@./deploy.sh

deploy-quick:
	@./deploy.sh --quick

optimize:
	@./scripts/optimize.sh

backup:
	@./scripts/backup.sh

backup-full:
	@./scripts/backup.sh full

rollback:
	@./scripts/rollback.sh list

# Maintenance
clean:
	@./scripts/clear-cache.sh

clean-all:
	@./scripts/clear-cache.sh --all

fix-perms:
	@./scripts/fix-permissions.sh

audit:
	@./scripts/security-audit.sh

# Versioning
version:
	@./scripts/bump-version.sh show

bump-patch:
	@./scripts/bump-version.sh patch

bump-minor:
	@./scripts/bump-version.sh minor

bump-major:
	@./scripts/bump-version.sh major

bump-build:
	@./scripts/bump-version.sh build

release:
	@./scripts/release.sh patch

release-minor:
	@./scripts/release.sh minor

release-major:
	@./scripts/release.sh major

# Artisan shortcuts
migrate:
	@php artisan migrate

migrate-fresh:
	@php artisan migrate:fresh --seed

seed:
	@php artisan db:seed

queue:
	@php artisan queue:work

schedule:
	@php artisan schedule:work

tinker:
	@php artisan tinker

# Docker (if using)
docker-up:
	@docker-compose up -d

docker-down:
	@docker-compose down

docker-logs:
	@docker-compose logs -f

# Health check
health:
	@curl -s http://localhost:8000/health.php | jq . 2>/dev/null || curl -s http://localhost:8000/health.php
