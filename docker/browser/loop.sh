#!/bin/sh
# O navegador do Radar dentro do próprio compose (serviço browser-loop), sem systemd nem acesso ao
# Docker do host: confere a pasta de pedidos e, quando há lote, roda uma rodada do capture.mjs.
# O Laravel lê as respostas pelo agendador (secmon:lookalikes-ingest, a cada 5 minutos).
WORK="${WORK_DIR:-/work}"
INTERVALO="${SECMON_BROWSER_INTERVALO:-30}"
PRAZO="${SECMON_BROWSER_PRAZO:-2700}"

trap 'exit 0' TERM INT

while :; do
    # Lote que ficou pela metade (rodada derrubada): o Laravel já o deu como falha.
    find "$WORK/work" -name '*.json' -mmin +120 -delete 2>/dev/null
    if ls "$WORK"/inbox/*.json >/dev/null 2>&1; then
        echo "$(date '+%F %T') $(ls "$WORK"/inbox/*.json | wc -l) lote(s) na fila: abrindo o navegador"
        timeout -s KILL "$PRAZO" node /app/capture.mjs || echo "$(date '+%F %T') rodada terminou com erro ($?)"
    fi
    sleep "$INTERVALO" &
    wait $!
done
