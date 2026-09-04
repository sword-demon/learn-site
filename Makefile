# Learn Site — 日常命令走 Docker Compose (OrbStack).
# 宿主机直接跑 PHP / Node 不构成验收.

COMPOSE      := docker compose
COMPOSE_TEST := docker compose -f compose.yaml -f compose.test.yaml --profile test
COMPOSE_DBG  := docker compose -f compose.yaml -f compose.debug.yaml
COMPOSE_E2E  := API_PORT=18787 ADMIN_PORT=18081 WEB_PORT=18080 docker compose -p learn-site-e2e -f compose.yaml -f compose.test.yaml --profile test
SERVICE      ?= api
API_PORT     ?= 8787

.DEFAULT_GOAL := help

.PHONY: help env bootstrap up down restart ps logs build rebuild rebuild-web rebuild-admin rebuild-api rebuild-all \
	migrate seed backup restore rehearse-restore verify-images verify-migrations verify-runtime-boundaries \
	health sh-api lint typecheck phpstan test test-api test-web test-fmt test-e2e test-perf e2e-down debug prototype

help:
	@echo "make bootstrap   # .env + 构建启动 + 迁移 + 种子 + 健康检查"
	@echo "make up          # docker compose up -d --build"
	@echo "make down        # 停栈 (保留卷)"
	@echo "make restart     # 仅重启容器，不重新构建 (改源码后无效)"
	@echo "make rebuild     # 重新构建并重启 SERVICE=$(SERVICE) (改源码后必做)"
	@echo "make rebuild-web / rebuild-admin / rebuild-api  # 按服务重建"
	@echo "make rebuild-all # 重建 api + web + admin"
	@echo "make ps          # 容器状态"
	@echo "make logs        # 跟日志 SERVICE=$(SERVICE)"
	@echo "make health      # curl API /health"
	@echo "make migrate     # 迁移前备份 + phinx migrate + 状态/健康校验"
	@echo "make seed        # phinx seed:run"
	@echo "make backup      # 通过 Compose 同时备份 MySQL 与 uploads"
	@echo "make rehearse-restore BACKUP_DIR=... # 临时 Compose project 恢复演练"
	@echo "make verify-images / verify-migrations / verify-runtime-boundaries"
	@echo "make sh-api      # 进入 api 容器"
	@echo "make lint        # 前端 ESLint"
	@echo "make typecheck   # 前端 vue-tsc"
	@echo "make phpstan     # Compose 内 PHPStan（512M）"
	@echo "make test        # api-test + frontend-test"
	@echo "make test-api    # PHPUnit (compose test profile)"
	@echo "make test-web    # 前端 typecheck + build"
	@echo "make test-e2e    # 独立 Compose project 跑管理端与学习端 Playwright"
	@echo "make test-perf   # 重置 E2E 夹具并跑单用户 95%/2s 性能冒烟"
	@echo "make e2e-down    # 删除独立 E2E project 及其测试卷"
	@echo "make debug       # 额外暴露 MySQL 3306"
	@echo "make prototype   # 打开 throwaway HTML 原型 :4173"

.env:
	test -f .env || cp .env.example .env

env: .env

bootstrap: .env up migrate seed health

up: .env
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart $(SERVICE)

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100 $(SERVICE)

build:
	$(COMPOSE) build

# 修改源码后必须 rebuild，仅 restart 不会更新镜像内静态资源 / PHP 代码。
# compose.test.yaml 的 frontend-test / api-test 为一次性测试容器，不用于日常预览。
rebuild:
	$(COMPOSE) up -d --build --no-deps $(SERVICE)

rebuild-web:
	$(COMPOSE) up -d --build --no-deps web

rebuild-admin:
	$(COMPOSE) up -d --build --no-deps admin

rebuild-api:
	$(COMPOSE) up -d --build --no-deps api

rebuild-all:
	$(COMPOSE) up -d --build --no-deps api web admin

migrate:
	COMPOSE="$(COMPOSE)" BACKUP_DIR="$(BACKUP_DIR)" bash ops/backup/migrate.sh

backup:
	COMPOSE="$(COMPOSE)" BACKUP_DIR="$(BACKUP_DIR)" bash ops/backup/backup.sh

restore:
	COMPOSE="$(COMPOSE)" BACKUP_DIR="$(BACKUP_DIR)" bash ops/backup/restore.sh "$(BACKUP_DIR)"

rehearse-restore:
	COMPOSE="$(COMPOSE)" BACKUP_DIR="$(BACKUP_DIR)" bash ops/backup/rehearse-restore.sh

verify-images:
	bash scripts/verify-images.sh

verify-migrations:
	COMPOSE="$(COMPOSE)" bash scripts/verify-migrations.sh

verify-runtime-boundaries:
	bash scripts/verify-runtime-boundaries.sh

seed:
	$(COMPOSE) exec -T api php vendor/bin/phinx seed:run

health:
	curl -sf http://127.0.0.1:$(API_PORT)/health && echo

sh-api:
	$(COMPOSE) exec api bash

lint:
	pnpm lint

typecheck:
	pnpm --filter @learn-site/admin typecheck
	pnpm --filter @learn-site/web typecheck

phpstan: .env
	$(COMPOSE_TEST) run --rm --build --no-deps api-test sh -c 'php -d memory_limit=512M vendor/bin/phpstan analyse app --no-progress --memory-limit=512M'

test: test-api test-web test-fmt

test-api: .env
	$(COMPOSE_TEST) run --rm api-test

test-web: .env
	$(COMPOSE_TEST) run --rm frontend-test

test-e2e: .env
	$(COMPOSE_E2E) up -d --build mysql redis api admin web
	$(COMPOSE_E2E) exec -T api php vendor/bin/phinx migrate -e runtime
	$(COMPOSE_E2E) run --rm --build e2e-fixture
	$(COMPOSE_E2E) run --rm --build e2e

test-perf: .env
	$(COMPOSE_E2E) up -d --build mysql redis api
	$(COMPOSE_E2E) exec -T api php vendor/bin/phinx migrate -e runtime
	$(COMPOSE_E2E) run --rm --build e2e-fixture
	$(COMPOSE_E2E) exec -T api bash tests/perf/timing.sh

e2e-down:
	$(COMPOSE_E2E) down -v

test-fmt: .env
	$(COMPOSE_TEST) run --rm api-fmt

debug: .env
	$(COMPOSE_DBG) up -d

prototype:
	sh scripts/prototype.sh
