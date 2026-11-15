#!/usr/bin/env bash
# Prepara o servidor de produção para o CI/CD do Security Monitor. Roda como root, no
# servidor, quantas vezes precisar (cada passo só faz o que falta):
#
#   scp -r ci/servidor root@203.0.113.10:/tmp/secmon-ci
#   ssh root@203.0.113.10 'SECMON_RUNNER_TOKEN=<token> bash /tmp/secmon-ci/instalar.sh'
#
# O token de registro do runner vale 1 hora:
#   gh api -X POST repos/romulano/Security-monitor/actions/runners/registration-token --jq .token
# Sem token, o runner não é registrado (o resto é feito do mesmo jeito).
#
#   bash ci/servidor/instalar.sh --remover-pentest
# desliga e tira a stack de testes (unidades, 2ª camada de rede e scripts), deixando as
# pastas, a configuração do host e as imagens.
#
# O que fica no servidor:
#   - /root/.ssh/secmon-deploy        deploy key SÓ LEITURA do repositório
#   - /root/site/secmon               checkout git da main (o .env e o resto não rastreado ficam)
#   - /usr/local/sbin/secmon-deploy   o deploy (root:root 0755)
#   - /etc/sudoers.d/secmon-runner    o runner só pode chamar o secmon-deploy
#   - usuário secmon-runner           sem senha, sem shell, fora do grupo docker
#   - /etc/systemd/system/secmon-browser.{path,service}
#                                    gatilho do navegador do Radar (ci/servidor/unidades.sh:
#                                    igual ao instalado não muda nada; a versão trocada fica
#                                    em /root/backups/secmon/systemd)
#   - stack de testes (ci/servidor/pentest.sh), tudo DESLIGADO:
#       /var/lib/secmon-pentest (root 0700): DISABLED, raiz.inode, vistos/, parados/, work/
#       app/pentest no volume storage (root 0755) com inbox/ stop/ outbox/ logs/ do app e status/ do root
#       /etc/secmon/pentest.env (0600, só se não existe), 7 unidades secmon-pentest* e
#       /usr/local/sbin/secmon-pentest-{desligar,religar}
#   - /opt/actions-runner/secmon      runner do GitHub Actions (serviço systemd), rótulo secmon-prod
set -euo pipefail

REPO=romulano/Security-monitor
DIR=/root/site/secmon
KEY=/root/.ssh/secmon-deploy
RUNNER_USER=secmon-runner
RUNNER_DIR=/opt/actions-runner/secmon
RUNNER_LABEL=secmon-prod
AQUI=$(dirname "$(realpath "$0")")

[[ $(id -u) == 0 ]] || { echo "Rode como root." >&2; exit 1; }

etapa() { printf '\n==> %s\n' "$*"; }

if [[ ${1:-} == --remover-pentest ]]; then
    # shellcheck source=ci/servidor/unidades.sh
    . "$AQUI/unidades.sh"
    # shellcheck source=ci/servidor/pentest.sh
    . "$AQUI/pentest.sh"
    etapa "Stack de testes: removendo (as pastas, a configuração do host e as imagens ficam)"
    remover_pentest "$DIR"
    exit 0
fi

etapa "Deploy key (só leitura)"
install -d -m 700 /root/.ssh
[[ -f $KEY ]] || ssh-keygen -q -t ed25519 -N '' -C "secmon-deploy@$(hostname)" -f "$KEY"
# Chaves do github.com tiradas da API (HTTPS), não de um ssh-keyscan às cegas.
if ! ssh-keygen -F github.com -f /root/.ssh/known_hosts >/dev/null 2>&1; then
    curl -fsS https://api.github.com/meta \
        | python3 -c 'import json,sys; print("\n".join("github.com " + k for k in json.load(sys.stdin)["ssh_keys"]))' \
        >>/root/.ssh/known_hosts
fi
export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o BatchMode=yes"
if ! git ls-remote "git@github.com:$REPO.git" HEAD >/dev/null 2>&1; then
    echo "Cadastre esta chave em https://github.com/$REPO/settings/keys (sem permissão de escrita) e rode de novo:"
    cat "$KEY.pub"
    exit 3
fi
echo "    acesso de leitura ao $REPO ok"

etapa "Checkout git em $DIR"
git config --global --get-all safe.directory | grep -qx "$DIR" || git config --global --add safe.directory "$DIR"
cd "$DIR"
if [[ ! -d .git ]]; then
    git init -q -b main
    git remote add origin "git@github.com:$REPO.git"
fi
git fetch --quiet origin main
if ! git rev-parse --verify --quiet HEAD >/dev/null; then
    # Só aponta o HEAD para a main; os arquivos ficam como estão até o primeiro deploy.
    git reset -q origin/main
fi
git status --short --untracked-files=no | sed 's/^/    diferente da main: /' || true
echo "    HEAD $(git rev-parse --short HEAD)"

etapa "Script de deploy e sudoers"
install -m 0755 -o root -g root "$AQUI/secmon-deploy" /usr/local/sbin/secmon-deploy
id "$RUNNER_USER" >/dev/null 2>&1 \
    || useradd --system --create-home --home-dir "/home/$RUNNER_USER" --shell /usr/sbin/nologin "$RUNNER_USER"
regra=$(mktemp)
cat >"$regra" <<EOF
# CI/CD do Security Monitor: o runner do GitHub Actions só pode rodar o deploy como root.
Defaults:$RUNNER_USER !requiretty
$RUNNER_USER ALL=(root) NOPASSWD: /usr/local/sbin/secmon-deploy
EOF
visudo -cqf "$regra"
install -m 0440 -o root -g root "$regra" /etc/sudoers.d/secmon-runner
rm -f "$regra"
echo "    $(sudo -l -U "$RUNNER_USER" | grep secmon-deploy | sed 's/^ *//')"

etapa "Navegador do Radar: gatilho do systemd"
# Os modelos vêm do checkout (o commit no ar), para baterem com o run.sh que o
# .service chama. A pasta de troca é a do volume `storage` vista do host.
# shellcheck source=ci/servidor/unidades.sh
. "$AQUI/unidades.sh"
if [[ -d $DIR/docker/browser/systemd ]]; then
    volume=$(docker volume inspect -f '{{.Mountpoint}}' secmon_storage 2>/dev/null || echo /var/lib/docker/volumes/secmon_storage/_data)
    instalar_unidades "$DIR/docker/browser/systemd" "$volume/app/lookalike" "$DIR" secmon-browser.path
else
    echo "    $DIR ainda sem os arquivos (servidor novo): rode de novo depois do primeiro deploy."
fi

etapa "Stack de testes: pastas, configuração do host e unidades (todas desligadas)"
# shellcheck source=ci/servidor/pentest.sh
. "$AQUI/pentest.sh"
if [[ -d $DIR/docker/pentest/systemd ]]; then
    instalar_pentest "$(docker volume inspect -f '{{.Mountpoint}}' secmon_storage 2>/dev/null || echo /var/lib/docker/volumes/secmon_storage/_data)" "$DIR"
else
    echo "    $DIR ainda sem a stack de testes: rode de novo depois do deploy."
fi

etapa "Imagens sob demanda e a limpeza diária do Docker"
# O secmon-deploy constrói as imagens dos profiles (ci/servidor/perfis.sh), mas o cron do
# servidor apaga toda imagem sem container com mais de 24 h, e a do navegador só tem
# container quando há pedido. Sem ela, o deploy seguinte (ou o próprio navegador)
# reconstrói, o que leva minutos. O cron vale para todos os stacks da máquina: a troca
# fica para o dono, e aqui só o aviso.
cron=/etc/cron.d/docker-image-prune
if [[ -f $cron ]] && grep -q 'image prune' "$cron" && ! grep -q 'label!=secmon.manter=1' "$cron"; then
    echo "    aviso: $cron não guarda as imagens com o rótulo secmon.manter=1. Para guardar, acrescente"
    echo "    ao comando:  --filter \"label!=secmon.manter=1\""
else
    echo "    ok"
fi

etapa "Runner do GitHub Actions"
if [[ -f $RUNNER_DIR/.runner ]]; then
    echo "    já registrado: $(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8-sig"))["agentName"])' "$RUNNER_DIR/.runner")"
elif [[ -z ${SECMON_RUNNER_TOKEN:-} ]]; then
    echo "    sem SECMON_RUNNER_TOKEN: runner não registrado." >&2
    exit 4
else
    versao=$(curl -fsS https://api.github.com/repos/actions/runner/releases/latest | python3 -c 'import json,sys; print(json.load(sys.stdin)["tag_name"].lstrip("v"))')
    pacote="actions-runner-linux-x64-$versao.tar.gz"
    install -d -o "$RUNNER_USER" -g "$RUNNER_USER" "$RUNNER_DIR"
    cd "$RUNNER_DIR"
    curl -fsSLo "/tmp/$pacote" "https://github.com/actions/runner/releases/download/v$versao/$pacote"
    # Confere o SHA-256 publicado nas notas da versão.
    esperado=$(curl -fsS "https://api.github.com/repos/actions/runner/releases/tags/v$versao" \
        | python3 -c 'import json,re,sys; b=json.load(sys.stdin)["body"]; print(re.search(r"<!-- BEGIN SHA linux-x64 -->([0-9a-f]{64})", b).group(1))')
    echo "$esperado  /tmp/$pacote" | sha256sum -c --quiet
    sudo -u "$RUNNER_USER" tar xzf "/tmp/$pacote" -C "$RUNNER_DIR"
    rm -f "/tmp/$pacote"
    ./bin/installdependencies.sh >/dev/null
    sudo -u "$RUNNER_USER" ./config.sh --unattended \
        --url "https://github.com/$REPO" --token "$SECMON_RUNNER_TOKEN" \
        --name "$(hostname)-secmon" --labels "$RUNNER_LABEL" --work _work --replace
    ./svc.sh install "$RUNNER_USER" >/dev/null
    echo "    registrado: $(hostname)-secmon ($RUNNER_LABEL), versão $versao"
fi
cd "$RUNNER_DIR"
./svc.sh start >/dev/null 2>&1 || true
./svc.sh status | grep -E "Active:" | sed 's/^ */    /'
