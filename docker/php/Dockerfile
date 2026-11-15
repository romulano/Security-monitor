# syntax=docker/dockerfile:1

########################
# Stage 1 — build dos assets
########################
FROM node:22-alpine AS assets

WORKDIR /build
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


########################
# Stage 2 — nginx de produção (docker-compose.prod.yml, target: web)
########################
# Só o nginx e a configuração: o public/ (com os assets) chega pelo volume que
# o app preenche no boot. Sem o código nem o commit aqui dentro, a imagem só
# muda quando o docker/nginx muda, e o deploy comum não troca o nginx.
# Fica antes do runtime para o build sem --target continuar gerando o PHP.
FROM nginx:1.27-alpine AS web

# Log de acesso no horário de Brasília, como o app e o supervisor.
ENV TZ=America/Sao_Paulo

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/prod-site.conf /etc/nginx/secmon/site.conf
COPY docker/nginx/prod-upstream.conf /etc/nginx/secmon/upstream.conf


########################
# Stage 3 — base PHP (extensões e configuração comuns a testes e runtime)
########################
FROM php:8.4-fpm-alpine AS php

RUN apk add --no-cache \
        git curl bash tzdata icu-dev libzip-dev postgresql-dev oniguruma-dev \
        libxml2-dev linux-headers supervisor \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo pdo_pgsql intl zip opcache bcmath pcntl \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-secmon.ini

# O código é bind-montado do host, então o php-fpm roda com o UID do host —
# sem isso, storage/ e bootstrap/cache/ ficariam sem permissão de escrita.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app 2>/dev/null || true \
    && adduser -u "${UID}" -G "$(getent group "${GID}" | cut -d: -f1)" -S -D -H app 2>/dev/null || true
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
# Filas e agendador em produção (serviço `supervisor` do docker-compose.prod.yml).
COPY docker/supervisor/secmon.conf /etc/supervisor/secmon.conf

ENV TZ=America/Sao_Paulo \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html


########################
# Stage 4 — testes (docker-compose.ci.yml, target: test)
########################
# Mesmo PHP e mesmas extensões do runtime, mais as dependências de dev
# (PHPUnit). A pasta tests/ fica fora do contexto principal (.dockerignore):
# chega pelo contexto nomeado `tests` (additional_contexts no compose do CI).
FROM php AS test

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --no-autoloader

COPY . .
COPY --from=tests . ./tests
COPY --from=assets /build/public/build ./public/build

# Como no boot do runtime (entrypoint): o .env da imagem é o exemplo, e o que
# vem do ambiente (compose, phpunit.xml) vale por cima dele.
RUN cp .env.example .env \
    && composer dump-autoload --optimize \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data \
               storage/framework/testing storage/logs storage/app/public bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

# Roda com o UID do app, que não escreve na raiz do código: sem cache de resultado.
CMD ["php", "artisan", "test", "--do-not-cache-result"]


########################
# Stage 5 — runtime PHP (o último: é o que o build sem --target gera)
########################
FROM php AS runtime

# Dependências primeiro, para a camada cachear.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --no-scripts --prefer-dist --no-autoloader

# Cópia embutida: mantém a imagem utilizável sem bind mount (deploy).
COPY . .
COPY --from=assets /build/public/build ./public/build

# /srv/public: ponto de montagem do volume do public/ (SECMON_PUBLIC_DIR). O
# volume novo herda o dono desta pasta, e o boot do app escreve nele.
ARG UID=1000
ARG GID=1000
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data \
               storage/logs storage/app/public bootstrap/cache /srv/public \
    && chmod -R 777 storage bootstrap/cache \
    && chown "${UID}:${GID}" /srv/public

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Commit que gerou a imagem (o deploy passa o SHA; fora dele fica "dev").
ARG REVISION=dev
LABEL org.opencontainers.image.revision=$REVISION

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
