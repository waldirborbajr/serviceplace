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
#   make rehealth   # reset-db + seed + health (útil após mudar o seed)
#   make sh         # shell dentro do container php
#   make health     # testa o login do usuário de teste (espera 200)
#   make seed       # força o seed (dispara um request)
#   make token      # imprime o JWT do usuário de teste
#
# Segredos: o .env na raiz é lido automaticamente (via --env-file).
#   JWT_SECRET=...
#   PASSWORD_PEPPER=...
#   (opcional) MYSQL_ROOT_PASSWORD=...
#   (opcional) MYSQL_PASSWORD=...

# Raiz do projeto = diretório deste Makefile, mesmo que `make -C` seja usado.
ROOT := $(CURDIR)

# Compose sempre lê o .env da raiz.
COMPOSE := docker compose -f $(ROOT)/docker/docker-compose.yaml --env-file $(ROOT)/.env

API        := http://localhost:8080
SEED_EMAIL := teste@test.com
SEED_PASS  := 123

.PHONY: up upd down logs ps restart setup reset-db rehealth sh health seed token help env-check

help:
	@echo "Alvos disponíveis:"
	@echo "  make setup      Cria data/ e ajusta permissões (rodar uma vez)"
	@echo "  make up         Sobe containers em foreground (com --build)"
	@echo "  make upd        Sobe containers em background (com --build)"
	@echo "  make down       Para e remove containers"
	@echo "  make logs       Acompanha logs"
	@echo "  make ps         Status dos containers"
	@echo "  make restart    down + up (foreground)"
	@echo "  make reset-db   Apaga data/platform.db"
	@echo "  make rehealth   reset-db + seed + health"
	@echo "  make sh         Shell no container php"
	@echo "  make health     Testa login do usuário de teste (espera 200)"
	@echo "  make seed       Dispara um request para rodar migrations + seed"
	@echo "  make token      Imprime o JWT do usuário de teste"
	@echo "  make env-check  Mostra se o .env da raiz foi carregado"
	@echo ""
	@echo "Usuário de teste (seed automático): $(SEED_EMAIL) / $(SEED_PASS)"

# Garante que o .env exista antes de qualquer alvo que use $(COMPOSE).
# (Não falha se ausente — os fallbacks do compose entram em ação.)
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

# Dispara um request qualquer para garantir que db_migrate() rodou.
seed:
	@curl -sS -o /dev/null -w "seed ok (HTTP %{http_code})\n" \
		$(API)/providers

# Smoke test: login do usuário de teste deve retornar 200.
health:
	@curl -sS -o /dev/null -w "HTTP %{http_code}\n" \
		$(API)/auth/login \
		-H "Content-Type: application/json" \
		-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}'

# Imprime o JWT (útil para testar /me).
token:
	@curl -sS $(API)/auth/login \
		-H "Content-Type: application/json" \
		-d '{"email":"$(SEED_EMAIL)","password":"$(SEED_PASS)"}' \
		| sed -n 's/.*"token":"\([^"]*\)".*/\1/p'
