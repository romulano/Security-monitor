# Servidor de envio do Security Monitor: Postfix só de saída, com assinatura DKIM (OpenDKIM).
#
# Sem porta publicada e só na rede interna do stack: só o app e o supervisor
# falam com ele (mail:25). Não recebe e-mail da internet; o recebimento do
# example.com continua no Email Routing da Cloudflare. A configuração sai do
# entrypoint.sh (variáveis MAIL_*), a chave DKIM nasce no primeiro boot no volume
# e o log de entrega (que o Admin → E-mails lê) fica em /var/log/mail.
FROM alpine:3.22

RUN apk add --no-cache postfix opendkim opendkim-utils ca-certificates tzdata \
    && mkdir -p /etc/opendkim/keys /var/log/mail /run/opendkim \
    && chown opendkim:opendkim /run/opendkim

COPY entrypoint.sh /usr/local/bin/secmon-mail
COPY header_checks /etc/postfix/header_checks

EXPOSE 25

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD postfix status >/dev/null 2>&1 && pgrep -x opendkim >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/secmon-mail"]
