#!/usr/bin/env bash
# Teste do servidor de envio (docker/mail), com as travas de produção:
#
#   1. o app (um container da rede interna) envia como @example.com e a mensagem
#      chega assinada: o DKIM confere com a chave que o container publicou;
#   2. remetente de outro domínio é recusado na hora;
#   3. o Received com o nome e o IP internos do app não sai do servidor;
#   4. o log de entrega registra status=sent e o app (outro usuário) consegue lê-lo.
#
# Em vez da internet, o MAIL_RELAYHOST manda tudo a um Mailpit na mesma rede.
# O lado do app é o verificar.py, ao lado deste arquivo.
#   MAIL_IMAGE=secmon/mail:ci bash docker/mail/test/run.sh
set -euo pipefail

IMAGE=${MAIL_IMAGE:-secmon/mail:ci}
AQUI=$(cd "$(dirname "$0")" && pwd)
ID=$$
NET=secmon-mail-teste-$ID
SINK=mailpit-$ID
MAIL=mail-$ID

limpar() {
    docker rm -f "$SINK" "$MAIL" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap limpar EXIT

docker network create "$NET" >/dev/null
docker run -d --name "$SINK" --network "$NET" axllent/mailpit:v1.27 >/dev/null
# As mesmas capacidades do docker-compose.prod.yml.
docker run -d --name "$MAIL" --network "$NET" --network-alias mail \
    -e MAIL_HELO=mail.teste.invalid -e MAIL_DKIM_SELECTOR=teste \
    -e MAIL_RELAYHOST="[$SINK]:1025" \
    --cap-drop ALL --cap-add CHOWN --cap-add DAC_OVERRIDE --cap-add FOWNER \
    --cap-add SETUID --cap-add SETGID --cap-add NET_BIND_SERVICE --cap-add KILL \
    --security-opt no-new-privileges:true \
    "$IMAGE" >/dev/null

estado=starting
for _ in $(seq 1 40); do
    estado=$(docker inspect -f '{{.State.Health.Status}}' "$MAIL" 2>/dev/null || echo starting)
    [[ $estado == healthy ]] && break
    sleep 1
done
if [[ $estado != healthy ]]; then
    docker logs "$MAIL" >&2
    echo "FALHA: o servidor de envio não ficou saudável" >&2
    exit 1
fi

docker run --rm --network "$NET" \
    -e CHAVE="$(docker exec "$MAIL" cat /var/log/mail/dkim.txt)" -e SINK="$SINK" \
    -v "$AQUI/verificar.py:/verificar.py:ro" \
    python:3.12-alpine sh -c 'pip install -q --disable-pip-version-check --root-user-action=ignore dkimpy==1.1.8 && python /verificar.py'

# O host chega à rede interna pelo gateway dela: tem que ser recusado (só containers enviam).
ip_mail=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$MAIL")
docker run --rm --network host python:3.12-alpine python -c "
import smtplib
with smtplib.SMTP('$ip_mail', 25, local_hostname='host', timeout=10) as s:
    s.ehlo('host')
    try:
        s.sendmail('bot@example.com', ['alguem@exemplo.test'], 'Subject: do host\r\n\r\nx\r\n')
    except smtplib.SMTPRecipientsRefused as e:
        codigo, motivo = next(iter(e.recipients.values()))
        assert codigo >= 500 and b'Client host rejected' in motivo, (codigo, motivo)
        print('ok: o host (gateway da rede) foi recusado,', codigo)
    else:
        raise SystemExit('FALHA: o host conseguiu enviar pelo servidor')
"

sleep 2
if ! docker exec "$MAIL" grep -q 'status=sent' /var/log/mail/mail.log; then
    docker exec "$MAIL" cat /var/log/mail/mail.log >&2
    echo "FALHA: o log de entrega não registrou status=sent" >&2
    exit 1
fi
docker exec -u 1000:1000 "$MAIL" head -c1 /var/log/mail/mail.log >/dev/null
echo "ok: log de entrega com status=sent, legível por outro usuário"
