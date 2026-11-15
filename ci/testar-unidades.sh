#!/usr/bin/env bash
# Teste do CI, sem root e sem rede: as unidades do navegador do Radar que o
# ci/servidor/instalar.sh instala (instalar_unidades, de ci/servidor/unidades.sh).
# O /etc/systemd/system vira um diretório temporário, e o systemctl é de mentira:
# anota as chamadas e guarda o estado de cada unidade em arquivos.
#
#   bash ci/testar-unidades.sh
#
# O caso que mais importa é o de produção: as duas unidades foram instaladas à mão
# em 23/10/2026 e estão ligadas (o .path ativo, esperando pedido). Com o conteúdo de
# lá (copiado abaixo do `systemctl cat` de 24/10), o instalador não pode mudar nada.
# Se o modelo mudar de propósito, esse caso passa a esperar a troca (cópia da
# anterior e try-restart do .path): atualize o texto junto.
#
# As condições vão entre aspas simples de propósito: o confere as avalia (eval) na hora.
# shellcheck disable=SC2016,SC2034
set -euo pipefail
export LC_ALL=C

AQUI=$(cd "$(dirname "$0")/.." && pwd)
MODELOS=$AQUI/docker/browser/systemd
PROD_DIR=/var/lib/docker/volumes/secmon_storage/_data/app/lookalike
PROD_ROOT=/root/site/secmon
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

PROD_PATH=$(cat <<'EOF'
# Gatilho do navegador do Radar de domínios: chegou pedido, sobe; terminou, morre.
# Local: `make browser-watch` (systemd do usuário). Servidor: em /etc/systemd/system.
# /var/lib/docker/volumes/secmon_storage/_data/app/lookalike vira a pasta de troca vista do host (no servidor, dentro do volume `storage`).
[Unit]
Description=Security Monitor: navegador do Radar de domínios (sobe quando há pedido)

[Path]
PathExistsGlob=/var/lib/docker/volumes/secmon_storage/_data/app/lookalike/inbox/*.json
Unit=secmon-browser.service

[Install]
WantedBy=default.target
EOF
)
PROD_SERVICE=$(cat <<'EOF'
# Disparado pelo secmon-browser.path. Abre as páginas pedidas e sai.
[Unit]
Description=Security Monitor: abre as páginas do Radar de domínios e sai

[Service]
Type=oneshot
Environment=SECMON_LOOKALIKE_DIR=/var/lib/docker/volumes/secmon_storage/_data/app/lookalike
ExecStart=/root/site/secmon/docker/browser/run.sh
TimeoutStartSec=3000
EOF
)

total=0
falhas=0
confere() {  # confere <descrição> <condição>
    total=$((total + 1))
    if eval "$2"; then
        printf '  ok    %s\n' "$1"
    else
        printf '  FALHA %s\n' "$1"
        falhas=$((falhas + 1))
        sed 's/^/        | /' "$tmp/saida"
        echo "        chamadas: $(chamadas)"
    fi
}

# systemctl de mentira.
export ESTADO=$tmp/estado SYSTEMCTL=$tmp/bin/systemctl UNIDADES_DONO=
mkdir -p "$tmp/bin"
cat >"$SYSTEMCTL" <<'EOF'
#!/usr/bin/env bash
echo "$*" >>"$ESTADO/chamadas"
case $1 in
    is-enabled) cat "$ESTADO/$2.habilitada" 2>/dev/null || { echo disabled; exit 1; } ;;
    is-active) cat "$ESTADO/$2.ativa" 2>/dev/null || { echo inactive; exit 3; } ;;
    enable) [[ $2 != --now ]] || { echo enabled >"$ESTADO/$3.habilitada"; echo active >"$ESTADO/$3.ativa"; } ;;
    daemon-reload | try-restart) ;;
    *) echo "systemctl falso: comando inesperado: $*" >&2; exit 99 ;;
esac
EOF
chmod +x "$SYSTEMCTL"

cenario() {  # cenario <título>: /etc e estado limpos
    echo "== $1"
    rm -rf "${tmp:?}/etc" "$tmp/copias" "$ESTADO"
    mkdir -p "$tmp/etc" "$ESTADO"
    : >"$ESTADO/chamadas"
    export UNIDADES_DIR=$tmp/etc UNIDADES_COPIAS=$tmp/copias
}
como_em_producao() {  # as unidades e o estado de produção em 24/10
    printf '%s\n' "$PROD_PATH" >"$tmp/etc/secmon-browser.path"
    printf '%s\n' "$PROD_SERVICE" >"$tmp/etc/secmon-browser.service"
    echo enabled >"$ESTADO/secmon-browser.path.habilitada"
    echo active >"$ESTADO/secmon-browser.path.ativa"
    echo static >"$ESTADO/secmon-browser.service.habilitada"
}
instalar() {  # instalar <modelos> <@DIR@> <@ROOT@>: como o instalar.sh chama
    status=0
    : >"$ESTADO/chamadas"
    (. "$AQUI/ci/servidor/unidades.sh" && instalar_unidades "$1" "$2" "$3" secmon-browser.path) >"$tmp/saida" 2>&1 || status=$?
}
# Só as chamadas que mudam alguma coisa (as consultas de estado ficam de fora).
chamadas() { grep -vE '^is-(enabled|active) ' "$ESTADO/chamadas" | paste -sd ';' - || true; }
igual_a() { [[ $(cat "$tmp/etc/$1") == "$2" ]]; }
copias() { find "$tmp/copias" -type f 2>/dev/null | wc -l; }
saida_tem() { grep -qF -- "$1" "$tmp/saida"; }

cenario "produção: unidades instaladas à mão e ligadas"
como_em_producao
instalar "$MODELOS" "$PROD_DIR" "$PROD_ROOT"
confere "nada muda: nem arquivo, nem daemon-reload, nem estado" '((status == 0)) && [[ -z $(chamadas) ]] && igual_a secmon-browser.path "$PROD_PATH" && igual_a secmon-browser.service "$PROD_SERVICE" && (($(copias) == 0))'
confere "o modelo do repositório dá exatamente o arquivo de produção" 'saida_tem "secmon-browser.path: igual ao instalado" && saida_tem "secmon-browser.service: igual ao instalado"'
confere "o estado continua o de antes (enabled, active)" 'saida_tem "secmon-browser.path: enabled, active" && saida_tem "secmon-browser.service: static, inactive"'
instalar "$MODELOS" "$PROD_DIR" "$PROD_ROOT"
confere "rodar de novo também não muda nada" '((status == 0)) && [[ -z $(chamadas) ]]'

cenario "servidor novo: sem as unidades"
instalar "$MODELOS" "$PROD_DIR" "$PROD_ROOT"
confere "instala as duas, iguais às de produção" '((status == 0)) && igual_a secmon-browser.path "$PROD_PATH" && igual_a secmon-browser.service "$PROD_SERVICE"'
confere "recarrega e liga só o .path (o .service é disparado por ele)" '[[ $(chamadas) == "daemon-reload;enable --now secmon-browser.path" ]] && saida_tem "secmon-browser.path: enabled, active"'

mkdir -p "$tmp/modelos"
cp "$MODELOS"/* "$tmp/modelos/"
sed -i 's|inbox/\*\.json|inbox/*.pedido.json|' "$tmp/modelos/secmon-browser.path"
cenario "o modelo do .path mudou (produção ligada)"
como_em_producao
instalar "$tmp/modelos" "$PROD_DIR" "$PROD_ROOT"
confere "troca o .path e guarda o anterior" '((status == 0 && $(copias) == 1)) && grep -q "inbox/\*\.pedido\.json" "$tmp/etc/secmon-browser.path" && [[ $(cat "$tmp"/copias/secmon-browser.path.*) == "$PROD_PATH" ]]'
confere "o .service, igual, fica" 'igual_a secmon-browser.service "$PROD_SERVICE"'
confere "recarrega e reinicia só o .path que estava ativo; nada de enable/disable/stop" '[[ $(chamadas) == "daemon-reload;try-restart secmon-browser.path" ]] && saida_tem "secmon-browser.path: enabled, active"'

cp "$MODELOS"/secmon-browser.path "$tmp/modelos/"
sed -i 's|TimeoutStartSec=3000|TimeoutStartSec=3100|' "$tmp/modelos/secmon-browser.service"
cenario "só o modelo do .service mudou"
como_em_producao
instalar "$tmp/modelos" "$PROD_DIR" "$PROD_ROOT"
confere "recarrega e não reinicia nada (um lote pode estar no meio)" '((status == 0)) && [[ $(chamadas) == "daemon-reload" ]] && grep -q TimeoutStartSec=3100 "$tmp/etc/secmon-browser.service"'

cp "$MODELOS"/secmon-browser.service "$tmp/modelos/"
sed -i 's|inbox/\*\.json|inbox/*.pedido.json|' "$tmp/modelos/secmon-browser.path"
cenario "o .path foi desligado à mão e o modelo mudou"
como_em_producao
echo disabled >"$ESTADO/secmon-browser.path.habilitada"
echo inactive >"$ESTADO/secmon-browser.path.ativa"
instalar "$tmp/modelos" "$PROD_DIR" "$PROD_ROOT"
confere "atualiza o arquivo e não religa (try-restart não sobe unidade parada)" '((status == 0)) && [[ $(chamadas) == "daemon-reload;try-restart secmon-browser.path" ]] && saida_tem "secmon-browser.path: disabled, inactive"'

cenario "caminho que quebraria o sed"
instalar "$MODELOS" "/var/lib/x|y" "$PROD_ROOT"
confere "recusa, sem escrever nada" '((status == 2)) && [[ -z $(ls -A "$tmp/etc") && -z $(chamadas) ]]'

echo "== $total verificações, $falhas falha(s)"
((falhas == 0))
