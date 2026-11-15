#!/usr/bin/env bash
set -e

cd /var/www/html

# O .env real vive no host e chega via env_file do compose; o arquivo dentro da
# imagem existe só para o artisan não reclamar. APP_KEY precisa ser o mesmo em
# todos os containers, então ele nunca é gerado aqui.
if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] ERRO: APP_KEY vazio. Rode 'make setup' (ou php artisan key:generate) no host antes de subir." >&2
    exit 1
fi

# Só o container "app" prepara o estado compartilhado; worker/scheduler apenas esperam.
if [ "${SECMON_ROLE:-app}" = "app" ]; then
    echo "[entrypoint] aguardando o PostgreSQL..."
    until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")) ? 0 : 1);'; do
        sleep 2
    done

    # bootstrap/cache vem do host: uma config cacheada de um .env antigo faria o
    # migrate usar credenciais velhas. O cache é refeito no fim do boot.
    php artisan config:clear --no-interaction >/dev/null

    echo "[entrypoint] rodando migrations..."
    php artisan migrate --force --no-interaction

    echo "[entrypoint] aguardando o Elasticsearch..."
    for _ in $(seq 1 60); do
        if curl -fsS "${ELASTICSEARCH_HOST:-http://es:9200}" >/dev/null 2>&1; then
            break
        fi
        sleep 2
    done

    php artisan secmon:es-setup || echo "[entrypoint] aviso: falha ao preparar o índice do Elasticsearch"
    # o symlink vive no host (bind mount), então só existe a criar no primeiro boot
    [ -L public/storage ] || php artisan storage:link || true

    # Produção: o nginx serve o public/ de um volume, e é o app que o preenche,
    # antes de começar a atender. Cada arquivo troca de uma vez (cópia e
    # rename), e o que não mudou só tem a data renovada. Os arquivos de versões
    # anteriores ficam: no deploy, a página que o app velho entregou continua
    # achando o CSS dela. O deploy apaga o que passou 7 dias sem ser renovado.
    if [ -n "${SECMON_PUBLIC_DIR:-}" ]; then
        echo "[entrypoint] copiando o public/ para ${SECMON_PUBLIC_DIR}..."
        (cd public && find . -type f) | while IFS= read -r arquivo; do
            origem="public/${arquivo#./}"
            destino="${SECMON_PUBLIC_DIR}/${arquivo#./}"
            if cmp -s "$origem" "$destino"; then
                touch "$destino"
            else
                mkdir -p "$(dirname "$destino")"
                cp "$origem" "$destino.novo"
                mv -f "$destino.novo" "$destino"
            fi
        done
    fi
else
    sleep 15
fi

php artisan config:cache --no-interaction || true
php artisan route:cache --no-interaction || true
php artisan view:cache --no-interaction || true

exec "$@"
