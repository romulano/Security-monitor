SHELL := /bin/bash
DC := docker compose
APP := $(DC) exec -T app
COMPOSER := docker run --rm -u "$$(id -u):$$(id -g)" -v "$$PWD":/app -w /app -e COMPOSER_HOME=/tmp/composer composer:2.8
NODE := docker run --rm -u "$$(id -u):$$(id -g)" -v "$$PWD":/app -w /app node:22-alpine

.DEFAULT_GOAL := help
.PHONY: help setup up down restart logs shell build assets deps migrate seed crawl crawl-all enrich reanalyze reindex ps test alerts-match alerts-digest alerts-recompile alerts-probe exploits-sync lookalikes-scan lookalikes-work browser-build browser-run browser-watch browser-unwatch

help: ## Lista os alvos disponíveis
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

setup: ## Primeira execução: .env, dependências, assets, containers, banco e índice
	@test -f .env || cp .env.example .env
	@grep -q '^APP_KEY=base64:' .env || php -r '$$e=file_get_contents(".env"); \
		file_put_contents(".env", preg_replace("/^APP_KEY=.*$$/m","APP_KEY=base64:".base64_encode(random_bytes(32)),$$e));' \
		2>/dev/null || echo "Gere APP_KEY manualmente se o PHP não estiver no host."
	@sed -i "s/^UID=.*/UID=$$(id -u)/;s/^GID=.*/GID=$$(id -g)/" .env
	$(MAKE) deps assets build up
	@echo "Aguardando o app subir..."; until $(APP) php artisan --version >/dev/null 2>&1; do sleep 2; done
	$(APP) php artisan db:seed --force
	@echo "Pronto: http://localhost:1515 (admin em /admin/login)"

deps: ## Instala as dependências PHP (via container do composer)
	$(COMPOSER) install --no-interaction --prefer-dist

assets: ## Compila CSS/JS para public/build
	$(NODE) sh -c 'npm install --no-audit --no-fund && npm run build'

build: ## Reconstrói a imagem da aplicação
	$(DC) build app

up: ## Sobe a stack
	$(DC) up -d

down: ## Derruba a stack (mantém os volumes)
	$(DC) down

restart: ## Reinicia app, worker, scheduler e nginx
	$(DC) restart app worker scheduler web

ps: ## Estado dos containers
	$(DC) ps

logs: ## Segue os logs da aplicação
	$(DC) logs -f app worker scheduler

shell: ## Abre um shell no container da aplicação
	$(DC) exec app bash

migrate: ## Roda as migrations
	$(APP) php artisan migrate --force

seed: ## Cria o admin e as fontes padrão
	$(APP) php artisan db:seed --force

crawl: ## Roda os bots das fontes que estão no horário
	$(APP) php artisan secmon:crawl

crawl-all: ## Força a coleta em todas as fontes habilitadas
	$(APP) php artisan secmon:crawl --all

enrich: ## Reprocessa artigos coletados que não viraram matéria
	$(APP) php artisan secmon:enrich-pending

reanalyze: ## Reanalisa o que já foi coletado com o driver de IA atual (use após configurar a chave)
	$(DC) exec -e HOME=/tmp app php artisan secmon:reanalyze

reindex: ## Recria o índice e reindexa todos os casos
	$(APP) php artisan secmon:reindex --fresh

exploits-sync: ## Atualiza o índice do Exploit-DB e liga os casos aos exploits dos CVEs deles
	$(APP) php artisan secmon:exploits-sync

alerts-match: ## Passa os casos recentes pelo matching dos alertas (SINCE=24h; só registra, não notifica)
	$(APP) php artisan secmon:alerts-match --since=$(or $(SINCE),24h) --sync

alerts-digest: ## Envia agora o resumo de hoje para quem ainda não recebeu
	$(APP) php artisan secmon:alerts-digest --force

alerts-recompile: ## Recompila as queries de todos os perfis e regrava o percolator
	$(APP) php artisan secmon:alerts-recompile

alerts-probe: ## Diagnóstico da descoberta num host, sem gravar nada (HOST=exemplo.com.br)
	$(APP) php artisan secmon:alerts-discover --probe=$(HOST)

lookalikes-scan: ## Varre agora o Radar de domínios de um domínio verificado (DOMAIN=exemplo.com.br)
	$(APP) php artisan secmon:lookalikes-scan $(DOMAIN) --sync

lookalikes-work: ## Processa só a fila do Radar de domínios e para quando ela esvazia (não toca na fila de análise)
	$(APP) php artisan queue:work --queue=lookalikes --stop-when-empty --timeout=600

browser-build: ## Constrói a imagem do navegador do Radar de domínios (docker/browser)
	$(DC) --profile browser build browser

browser-run: ## Sobe o navegador agora, se houver pedido na fila; ele sai ao terminar
	./docker/browser/run.sh

browser-watch: ## Instala o gatilho do systemd do usuário: o navegador sobe sozinho quando chega pedido
	@mkdir -p $$HOME/.config/systemd/user
	@for unit in secmon-browser.path secmon-browser.service; do \
		sed "s#@ROOT@#$$PWD#g; s#@DIR@#$$PWD/storage/app/lookalike#g" docker/browser/systemd/$$unit > $$HOME/.config/systemd/user/$$unit; done
	systemctl --user daemon-reload
	systemctl --user enable --now secmon-browser.path
	@systemctl --user --no-pager status secmon-browser.path | head -5

browser-unwatch: ## Remove o gatilho do systemd (o navegador só sobe com make browser-run)
	-systemctl --user disable --now secmon-browser.path
	rm -f $$HOME/.config/systemd/user/secmon-browser.path $$HOME/.config/systemd/user/secmon-browser.service
	systemctl --user daemon-reload

test: ## Roda a suíte de testes (banco secmon_test, isolado do de produção)
	@$(DC) exec -T db psql -U "$${DB_USERNAME:-secmon}" -d postgres -tc \
		"SELECT 1 FROM pg_database WHERE datname='secmon_test'" | grep -q 1 \
		|| $(DC) exec -T db psql -U "$${DB_USERNAME:-secmon}" -d postgres -c \
		'CREATE DATABASE secmon_test OWNER secmon'
	@# O entrypoint roda `config:cache` no boot, e config cacheada ignora tanto
	@# o <env> do phpunit.xml quanto as variáveis passadas aqui — sem limpar
	@# antes, a suíte rodaria contra o banco de produção.
	@$(APP) php artisan config:clear >/dev/null
	@# Rotas cacheadas no boot também ficariam velhas: rota nova não existiria no teste.
	@$(APP) php artisan route:clear >/dev/null
	@# As variáveis vêm pelo ambiente do processo: o compose injeta o .env no
	@# container, e isso vence o <env> do phpunit.xml.
	-$(DC) exec -T -e HOME=/tmp -e APP_ENV=testing -e DB_DATABASE=secmon_test \
		-e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
		app php artisan test $(ARGS)
	@$(APP) php artisan config:cache >/dev/null
	@$(APP) php artisan route:cache >/dev/null
