# Makefile — ServicePlace (dev)
#
# Uso: make <alvo>
#   make setup      # cria data/ com dono/permissão corretos (rodar uma vez)
#   make up         # sobe containers em foreground (com --build)
#   make upd        # sobe containers em background (com --build)
#   make down       # para e remove containers
#   make logs       # acompanha logs
#   make ps         # status dos containers
#   make restart    # down + up
#   make reset-db   # apaga o SQLite; migrations + seed rodam no próximo request
#   make rehealth   # reset-db + seed + health
#   make sh         # shell dentro do container php
#   make health     # testa o login do contratante (espera 200)
#   make health-all # testa o login de todos os usuários do seed
#   make seed       # força o seed (dispara um request)
#   make token      # imprime o JWT do contratante
#   make pay-test   # smoke test do fluxo de pagamento (listar, done, release)
#   make reset-rate # limpa o rate limit de /auth/login (útil após vários health)
#   make env-check  # mostra se o .env da raiz foi carregado
#
# Segredos: o .env na raiz é lido automaticamente (via --env-file).
#   JWT_SECRET=...
#   PASSWORD_PEPPER=...
#   (opcional) MYSQL_ROOT_PASSWORD=...
#   (opcional) MYSQL_PASSWORD=...

ROOT := $(CURDIR)

COMPOSE := docker compose -f $(ROOT)/docker/docker-compose.yaml --env-file $(ROOT)/.env

API        := http://localhost:8080
SEED_EMAIL := cliente@test.com
SEED_PASS  := 123

# Usuários criados pelo seed de dev (src/Core/Migrations.php -> seed_dev_data()).
SEED_USERS := cliente@test.com fotografo@test.com garcom@test.com dj@test.com

.PHONY: up upd down logs ps restart setup reset-db rehealth sh health health-all seed token pay-test reset-rate help env-check

help:
	@echo "Alvos disponíveis:"
	@echo "  make setup       Cria data/ e ajusta permissões (rodar uma vez)"
	@echo "  make up          Sobe containers em foreground (com --build)"
	@echo "  make upd         Sobe containers em background (com --build)"
	@echo "  make down        Para e remove containers"
	@echo "  make logs        Acompanha logs"
	@echo "  make ps          Status dos containers"
	@echo "  make restart     down + up (foreground)"
	@echo "  make reset-db    Apaga data/platform.db"
	@echo "  make rehealth    reset-db + seed + health"
	@echo "  make sh          Shell no container php"
	@echo "  make health      Testa login do contratante ($(SEED_EMAIL))"
	@echo "  make health-all  Testa login de todos os usuários do seed"
	@echo "  make seed        Dispara um request para rodar migrations + seed"
	@echo "  make token       Imprime o JWT do contratante"
	@echo "  make pay-test    Smoke test do fluxo de pagamento"
	@echo "  make reset-rate  Limpa o rate limit de /auth/login"
	@echo "  make env-check   Mostra se o .env da raiz foi carregado"
	@echo ""
	@echo "Usuário de teste (contratante): $(SEED_EMAIL) / $(SEED_PASS)"
	@echo "Usuários do seed: $(SEED_USERS)"

env-check:
	@if [ -f "$(ROOT)/.env" ]; then \
		echo "OK: $(ROOT)/.env encontrado"; \
	else \
		echo "AVISO: $(ROOT)/.env não existe — usando defaults do compose"; \
	fi

setup:
	mkdir -p $(ROOT)/data
	sudo chown -R 82:82 $(ROOT)/data
	chmod 700 $(ROOT)/data
	@echo "data/ pronto (dono 82:82, modo 700)"

up: env-check
	$(COMPOSE) up --build

upd: env-check
	$(COMPOSE) up -d --build

down: env-check
	$(COMPOSE) down

logs: env-check
	$(COMPOSE) logs -f

ps: env-check
	$(COMPOSE) ps

restart: down up

reset-db:
	sudo rm -f $(ROOT)/data/platform.db
	@echo "platform.db removido; migrations + seed rodam no próximo request"

rehealth: reset-db seed health

sh: env-check
	$(COMPOSE) exec php sh

seed:
	@curl -sS -o /dev/null -w "seed ok (HTTP %{http_code})\n" \
		$(API)/providers

# Smoke test: login do contratante deve retornar 200.
health:
	@curl -sS -o /dev/null -w "HTTP %{http_code}\n" \
		$(API)/auth/login \
		-H "Content-Type: application/json" \
		-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}'

# Testa login de todos os usuários do seed. Falha (exit 1) se algum não retornar 200.
health-all:
	@fail=0; \
	for email in $(SEED_USERS); do \
		code=$$(curl -sS -o /dev/null -w "%{http_code}" \
			$(API)/auth/login \
			-H "Content-Type: application/json" \
			-d "{\"email\":\"$$email\",\"password\":\"$(SEED_PASS)\"}"); \
		if [ "$$code" = "200" ]; then \
			printf "  %-24s HTTP %s\n" "$$email" "$$code"; \
		else \
			printf "  %-24s HTTP %s  <-- FALHOU\n" "$$email" "$$code"; \
			fail=1; \
		fi; \
	done; \
	if [ $$fail -ne 0 ]; then \
		echo "health-all: pelo menos um usuário falhou"; \
		exit 1; \
	else \
		echo "health-all: todos OK"; \
	fi

# Imprime o JWT do contratante (útil para testar /me, /events, etc).
token:
	@curl -sS $(API)/auth/login \
		-H "Content-Type: application/json" \
		-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}' \
		| grep -o '"token":"[^"]*"' | cut -d'"' -f4

# Limpa o rate limit de /auth/login. Útil quando você rodou health/health-all
# muitas vezes e o servidor passou a retornar 429.
reset-rate:
	@if [ ! -f "$(ROOT)/data/platform.db" ]; then \
		echo "reset-rate: data/platform.db não existe (rode make setup e make seed antes)"; \
		exit 1; \
	fi; \
	sudo sqlite3 $(ROOT)/data/platform.db "DELETE FROM rate_limits WHERE endpoint = '/auth/login';"; \
	echo "rate limit de /auth/login limpo"

# Smoke test do fluxo de pagamento.
pay-test:
	@TOKEN=$$(curl -sS $(API)/auth/login \
		-H "Content-Type: application/json" \
		-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}' \
		| grep -o '"token":"[^"]*"' | cut -d'"' -f4); \
	if [ -z "$$TOKEN" ]; then \
		echo "pay-test: falha no login do contratante"; \
		echo "Diagnóstico:"; \
		echo "  - make health (deve retornar HTTP 200)"; \
		echo "  - make reset-rate (se o rate limit estourou)"; \
		echo "  - make ps (containers rodando?)"; \
		echo "Resposta do servidor:"; \
		curl -sS $(API)/auth/login \
			-H "Content-Type: application/json" \
			-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}'; \
		echo; \
		exit 1; \
	fi; \
	echo "== Pagamentos do evento 1 =="; \
	curl -sS $(API)/events/1/payments -H "Authorization: Bearer $$TOKEN"; \
	echo; \
	echo "== Marcando vínculo 1 (fotógrafo) como done =="; \
	curl -sS -X PATCH $(API)/events/1/professionals/1 \
		-H "Authorization: Bearer $$TOKEN" -H "Content-Type: application/json" \
		-d '{"status":"done"}'; \
	echo; \
	echo "== Liberando pagamento 1 =="; \
	curl -sS -X POST $(API)/payments/1/release \
		-H "Authorization: Bearer $$TOKEN"; \
	echo; \
	echo "== Pagamentos do evento 1 (após release) =="; \
	curl -sS $(API)/events/1/payments -H "Authorization: Bearer $$TOKEN"; \
	echo
