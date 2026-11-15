# shellcheck shell=bash
# Instala unidades do systemd (.path, .service e .timer) a partir dos modelos do repositório (docker/*/systemd/),
# trocando @DIR@ (a pasta de troca vista do host) e @ROOT@ (o checkout). Carregado
# pelo ci/servidor/instalar.sh; o teste (ci/testar-unidades.sh) usa as variáveis
# abaixo para rodar sem root.
#
# Conservador de propósito: em produção as unidades do navegador foram instaladas à
# mão (23/10/2026) e estão ligadas, e rodar o instalador não pode quebrá-las nem
# mudar o estado delas:
#   - conteúdo igual ao instalado: nada muda (nem daemon-reload);
#   - diferente: o arquivo antigo fica em $UNIDADES_COPIAS, o novo entra, o systemd
#     recarrega, e só o .path ou o .timer que estava ativo é reiniciado (try-restart:
#     continua ativo, com a versão nova). O .service nunca é reiniciado: pode estar no
#     meio de um lote, e a versão nova vale na próxima vez que ele subir;
#   - unidade que não existia: entra, e só a indicada é ligada (enable --now);
#   - nunca liga, desliga, para nem habilita uma unidade que já existia.
UNIDADES_DIR=${UNIDADES_DIR:-/etc/systemd/system}
UNIDADES_COPIAS=${UNIDADES_COPIAS:-/root/backups/secmon/systemd}
UNIDADES_DONO=${UNIDADES_DONO-root}
SYSTEMCTL=${SYSTEMCTL:-systemctl}

instalar_unidades() {  # instalar_unidades <pasta dos modelos> <@DIR@> <@ROOT@> [unidade a ligar se for nova]
    local modelos=$1 dir=$2 raiz=$3 ligar=${4:-} modelo nome destino novo recarregar=false
    local -a nomes=() mudaram=() novas=() dono=()
    # Os caminhos entram num `sed s|...|...|`: só caracteres que não quebram a troca.
    if [[ ! $dir =~ ^/[A-Za-z0-9._/-]+$ || ! $raiz =~ ^/[A-Za-z0-9._/-]+$ ]]; then
        echo "    caminho inválido para as unidades: '$dir' / '$raiz'" >&2
        return 2
    fi
    [[ -z $UNIDADES_DONO ]] || dono=(-o "$UNIDADES_DONO" -g "$UNIDADES_DONO")

    novo=$(mktemp)
    for modelo in "$modelos"/*.path "$modelos"/*.service "$modelos"/*.timer; do
        [[ -f $modelo ]] || continue
        nome=$(basename "$modelo")
        nomes+=("$nome")
        destino=$UNIDADES_DIR/$nome
        sed -e "s|@DIR@|$dir|g" -e "s|@ROOT@|$raiz|g" "$modelo" >"$novo"
        if [[ -f $destino ]] && cmp -s "$novo" "$destino"; then
            echo "    $nome: igual ao instalado"
            continue
        fi
        if [[ -f $destino ]]; then
            install -d -m 0700 "$UNIDADES_COPIAS"
            cp -p "$destino" "$UNIDADES_COPIAS/$nome.$(date -u +%Y%m%dT%H%M%SZ)"
            mudaram+=("$nome")
            echo "    $nome: atualizada (a anterior ficou em $UNIDADES_COPIAS)"
        else
            novas+=("$nome")
            echo "    $nome: instalada"
        fi
        install -m 0644 "${dono[@]}" "$novo" "$destino"
        recarregar=true
    done
    rm -f "$novo"
    if ((${#nomes[@]} == 0)); then
        echo "    nenhum modelo em $modelos" >&2
        return 1
    fi

    if $recarregar; then
        $SYSTEMCTL daemon-reload
        for nome in "${mudaram[@]}"; do
            if [[ $nome == *.path || $nome == *.timer ]]; then
                $SYSTEMCTL try-restart "$nome"
            fi
        done
        for nome in "${novas[@]}"; do
            if [[ $nome == "$ligar" ]]; then
                $SYSTEMCTL enable --now "$nome"
            fi
        done
    fi
    for nome in "${nomes[@]}"; do
        echo "    $nome: $($SYSTEMCTL is-enabled "$nome" 2>/dev/null || true), $($SYSTEMCTL is-active "$nome" 2>/dev/null || true)"
    done
}
