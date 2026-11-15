#!/usr/bin/env bash
# Sobe o navegador do Radar de domínios só quando há pedido, e ele morre
# ao terminar. Quem chama: o systemd (secmon-browser.path vê um
# arquivo novo em storage/app/lookalike/inbox) ou `make browser-run`.
#
# Prazo de cada rodada (SECMON_BROWSER_PRAZO, 45 min) aplicado no CONTAINER:
# `run -d`, `docker wait` com prazo, `docker stop` (SIGTERM e, depois de
# SECMON_BROWSER_PARADA segundos, SIGKILL) e `docker kill`. Antes era
# `timeout docker compose run`, que mata o cliente e deixa o container rodando
# (medido em 24/10/2026): o node é o PID 1 da imagem e não trata SIGTERM, então
# nem o sinal repassado pelo cliente o derrubava. Um sinal neste script (o
# TimeoutStartSec do systemd, um Ctrl+C) também derruba o container.
#
# No fim, o Laravel lê as respostas (secmon:lookalikes-ingest). O agendador
# também lê a cada 5 minutos, caso este passo falhe.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# A pasta de troca vista do host: no local é storage/ do projeto; no servidor fica
# dentro do volume Docker `storage` (o systemd passa SECMON_LOOKALIKE_DIR).
DIR="${SECMON_LOOKALIKE_DIR:-$ROOT/storage/app/lookalike}"
INGEST_SERVICE="${SECMON_INGEST_SERVICE:-app}"
# O capture.mjs tem orçamento próprio de 40 min (RUN_MS); o prazo é o teto, com folga.
PRAZO="${SECMON_BROWSER_PRAZO:-2700}"
# Depois do SIGTERM, quanto o container tem antes do SIGKILL.
PARADA="${SECMON_BROWSER_PARADA:-10}"
# Um prazo que o `timeout` não entende faria o `docker wait` voltar na hora, e o
# navegador seria derrubado no começo de toda rodada.
[[ $PRAZO =~ ^[1-9][0-9]*$ && $PARADA =~ ^[0-9]+$ ]] || { echo "prazo inválido: '$PRAZO' / '$PARADA'"; exit 1; }

cd "$ROOT"
[ -d "$DIR" ] || { echo "sem pasta de pedidos ($DIR)"; exit 0; }
# Subpastas com o mesmo dono da pasta (o usuário do app), mesmo quando quem roda é o root.
install -d -o "$(stat -c %u "$DIR")" -g "$(stat -c %g "$DIR")" "$DIR"/inbox "$DIR"/work "$DIR"/outbox "$DIR"/shots

exec 9>"$DIR/.run.lock"
flock -n 9 || { echo "navegador já está rodando"; exit 0; }

# Derruba e apaga um container do navegador. Sem erro se ele já não existe.
derrubar() {
    docker stop --timeout "$PARADA" "$1" >/dev/null 2>&1
    docker kill "$1" >/dev/null 2>&1
    docker rm -f "$1" >/dev/null 2>&1
    return 0
}
atual=""
sair() {
    echo "sinal recebido: derrubando o navegador"
    [ -z "$atual" ] || derrubar "$atual"
    exit "$1"
}
trap 'sair 143' TERM
trap 'sair 130' INT

# Container que ficou de uma vez anterior (este script morto com SIGKILL, servidor
# reiniciado no meio): com a trava acima, ninguém mais o está usando.
for velho in $(docker compose ps -a -q browser 2>/dev/null); do
    echo "container do navegador que ficou de antes (${velho:0:12}): derrubando"
    derrubar "$velho"
done

# Lote que ficou pela metade (navegador morto no meio): o Laravel já o deu como falha.
find "$DIR/work" -name '*.json' -mmin +120 -delete

for round in 1 2 3; do
    compgen -G "$DIR/inbox/*.json" >/dev/null || break
    echo "rodada $round: $(ls "$DIR"/inbox/*.json | wc -l) lote(s) na fila"
    # O id do container vai para o stdout; o progresso do compose, para o log.
    atual=$(docker compose run -d browser)
    [ -n "$atual" ] || { echo "navegador não subiu"; break; }
    # A saída do navegador continua no log (no servidor, o journal), como antes.
    docker logs -f "$atual" 2>&1 &
    logs=$!
    # Em segundo plano de propósito: o `wait` do bash é interrompido por um sinal (e o
    # trap roda na hora); com o comando em primeiro plano, o trap esperaria o prazo.
    timeout "$PRAZO" docker wait "$atual" >/dev/null &
    wait $!
    status=$?
    if [ "$status" = 124 ]; then
        echo "navegador passou do prazo de $PRAZO s: derrubando"
    else
        codigo=$(docker inspect -f '{{.State.ExitCode}}' "$atual" 2>/dev/null)
        [ "$codigo" = 0 ] || echo "navegador saiu com erro (${codigo:-?})"
    fi
    derrubar "$atual"
    atual=""
    wait "$logs" 2>/dev/null
done

docker compose exec -T "$INGEST_SERVICE" php artisan secmon:lookalikes-ingest || echo "leitura das respostas falhou; o agendador tenta de novo"
