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

# Usuários criados pelo seed de dev (index.php -> seed_dev_data()).
SEED_USERS := cliente@test.com fotografo@test.com garcom@test.com dj@test.com

.PHONY: up upd down logs ps restart setup reset-db rehealth sh health health-all seed token help env-check

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
		| sed -n 's/.*"token":"\([^"]*\)".*/\1/p'
