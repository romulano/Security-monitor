#!/bin/sh
# Boot do servidor de envio (docker/mail): monta a configuração do Postfix e do
# OpenDKIM a partir das variáveis, gera a chave DKIM se o volume ainda não tem,
# e sobe os dois. O Postfix fica em primeiro plano (o container vive com ele).
#
#   MAIL_DOMAIN         domínio do remetente e da assinatura (example.com)
#   MAIL_HELO           nome com que o servidor se apresenta: o nome reverso (PTR) do IP
#                       público do servidor, que volta para o mesmo IP
#   MAIL_DKIM_SELECTOR  seletor da chave (o registro é <seletor>._domainkey.<domínio>)
#   MAIL_RELAYHOST      só nos testes: entrega tudo a outro servidor em vez de ir à internet
set -eu

DOMAIN=${MAIL_DOMAIN:-example.com}
HELO=${MAIL_HELO:?defina MAIL_HELO: o nome reverso do IP do servidor}
SELECTOR=${MAIL_DKIM_SELECTOR:-secmon1}
KEYS=/etc/opendkim/keys
LOGS=/var/log/mail

# Quem pode mandar: só a rede interna do stack, onde este container está, e
# nunca o gateway dela, que é o próprio host (processos do host não enviam).
cidr=$(ip -o -f inet addr show eth0 | awk '{print $4}' | head -n1)
rede="$(ipcalc -n "$cidr" | cut -d= -f2)/${cidr#*/}"
gateway=$(ip route | awk '/^default/ {print $3; exit}')

# Chave DKIM: nasce uma vez e fica no volume. O valor do registro TXT vai para o
# volume do log, onde o Admin → E-mails mostra o que cadastrar na Cloudflare.
mkdir -p "$KEYS" "$LOGS"
if [ ! -s "$KEYS/$SELECTOR.private" ]; then
    opendkim-genkey -b 2048 -h sha256 -d "$DOMAIN" -s "$SELECTOR" -D "$KEYS"
    echo "secmon-mail: chave DKIM nova ($SELECTOR._domainkey.$DOMAIN)"
fi
chown -R opendkim:opendkim "$KEYS"
chmod 700 "$KEYS"
chmod 600 "$KEYS/$SELECTOR.private"
tr -d '\n' < "$KEYS/$SELECTOR.txt" | sed -e 's/^[^(]*(//' -e 's/).*$//' -e 's/"[[:space:]]*"//g' -e 's/"//g' \
    | tr -s '[:space:]' ' ' | sed -e 's/^ //' -e 's/ $//' > "$LOGS/dkim.txt"
printf '%s._domainkey.%s\n' "$SELECTOR" "$DOMAIN" > "$LOGS/dkim-name.txt"
chmod 644 "$LOGS/dkim.txt" "$LOGS/dkim-name.txt"

cat > /etc/opendkim/opendkim.conf <<EOF
Syslog                  no
UMask                   007
UserID                  opendkim
PidFile                 /run/opendkim/opendkim.pid
Mode                    s
Domain                  $DOMAIN
Selector                $SELECTOR
KeyFile                 $KEYS/$SELECTOR.private
Socket                  inet:8891@127.0.0.1
Canonicalization        relaxed/simple
SignatureAlgorithm      rsa-sha256
OversignHeaders         From
InternalHosts           127.0.0.1,$rede
EOF

postconf -e \
    "compatibility_level = 3.10" \
    "myhostname = $HELO" \
    "mydomain = $DOMAIN" \
    "myorigin = $DOMAIN" \
    "mydestination =" \
    "relay_domains =" \
    "local_recipient_maps =" \
    "local_transport = error:5.1.1 este servidor so envia" \
    "inet_interfaces = all" \
    "inet_protocols = ipv4" \
    "mynetworks = !$gateway/32, $rede" \
    "smtpd_client_restrictions = permit_mynetworks, reject" \
    "smtpd_helo_required = yes" \
    "smtpd_sender_restrictions = reject_non_fqdn_sender, check_sender_access inline:{ $DOMAIN=OK }, reject" \
    "smtpd_relay_restrictions = permit_mynetworks, reject" \
    "smtpd_recipient_restrictions = permit_mynetworks, reject" \
    "smtpd_banner = \$myhostname ESMTP" \
    "smtpd_tls_security_level = none" \
    "disable_vrfy_command = yes" \
    "message_size_limit = 10485760" \
    "smtpd_milters = inet:127.0.0.1:8891" \
    "non_smtpd_milters = inet:127.0.0.1:8891" \
    "milter_default_action = tempfail" \
    "milter_protocol = 6" \
    "header_checks = regexp:/etc/postfix/header_checks" \
    "smtp_tls_security_level = may" \
    "smtp_tls_CAfile = /etc/ssl/certs/ca-certificates.crt" \
    "smtp_tls_loglevel = 1" \
    "smtp_destination_concurrency_limit = 2" \
    "maximal_queue_lifetime = 1d" \
    "bounce_queue_lifetime = 1d" \
    "notify_classes = resource, software" \
    "relayhost = ${MAIL_RELAYHOST:-}" \
    "maillog_file = $LOGS/mail.log" \
    "maillog_file_permissions = 0644" \
    "maillog_file_rotate_suffix = %Y%m%d-%H%M%S" \
    "maillog_file_compressor = gzip"

newaliases
opendkim -x /etc/opendkim/opendkim.conf
echo "secmon-mail: $HELO envia por $DOMAIN para $rede (fora o host $gateway)"

# O log de entrega também aparece no `docker logs`. Acima de 20 MB ele roda, e
# os rodados saem com 7 dias (o app já leu o que precisava, minuto a minuto).
touch "$LOGS/mail.log"
tail -F -n0 "$LOGS/mail.log" 2>/dev/null &
(
    while sleep 3600; do
        if [ "$(stat -c %s "$LOGS/mail.log" 2>/dev/null || echo 0)" -gt 20971520 ]; then
            postfix logrotate
        fi
        find "$LOGS" -name 'mail.log.*' -mtime +7 -delete
    done
) &

exec postfix start-fg
