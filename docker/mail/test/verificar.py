"""Lado do "app" no teste do servidor de envio (docker/mail/test/run.sh).

Envia pela rede interna como o app faria, confere a recusa de remetente de fora
e lê no Mailpit a mensagem que saiu: assinada com DKIM d=example.com (a assinatura
confere com a chave que o container publicou) e sem o Received interno do app.
"""

import json
import os
import smtplib
import time
import urllib.request

import dkim

SINK = os.environ["SINK"]
CHAVE = os.environ["CHAVE"].encode()
API = f"http://{SINK}:8025/api/v1"


def enviar(remetente):
    with smtplib.SMTP("mail", 25, local_hostname="app-interno", timeout=20) as s:
        s.ehlo("app-interno")
        msg = (
            f"From: Security Monitor <{remetente}>\r\nTo: alguem@exemplo.test\r\n"
            f"Subject: teste {remetente}\r\nMessage-ID: <{time.time()}@example.com>\r\n\r\ncorpo do teste\r\n"
        )
        return s.sendmail(remetente, ["alguem@exemplo.test"], msg)


enviar("bot@example.com")
print("ok: @example.com aceito")

# O Postfix deixa a recusa do remetente para o RCPT (smtpd_delay_reject): vale o motivo, não a etapa.
try:
    enviar("golpe@outro.test")
    raise SystemExit("FALHA: remetente de outro domínio foi aceito")
except smtplib.SMTPRecipientsRefused as e:
    codigo, motivo = next(iter(e.recipients.values()))
    assert 500 <= codigo < 600 and b"Sender address rejected" in motivo, (codigo, motivo)
    print("ok: remetente de outro domínio recusado,", codigo, motivo.decode())
except smtplib.SMTPSenderRefused as e:
    assert 500 <= e.smtp_code < 600, e
    print("ok: remetente de outro domínio recusado,", e.smtp_code)

for _ in range(30):
    lista = json.load(urllib.request.urlopen(f"{API}/messages"))
    if lista.get("messages"):
        break
    time.sleep(1)
else:
    raise SystemExit("FALHA: a mensagem não chegou ao Mailpit")

mensagem = lista["messages"][0]["ID"]
bruto = urllib.request.urlopen(f"{API}/message/{mensagem}/raw").read()
cabecalhos = bruto.split(b"\r\n\r\n", 1)[0].replace(b"\r\n\t", b" ").replace(b"\r\n ", b" ")
assert b"DKIM-Signature:" in cabecalhos, "sem DKIM-Signature"
assert b"d=example.com" in cabecalhos and b"s=teste" in cabecalhos, "DKIM com domínio ou seletor errado"
assert b"app-interno" not in cabecalhos, "o Received interno do app saiu do servidor"


def dns(nome, timeout=5):
    assert nome == b"teste._domainkey.example.com.", nome
    return CHAVE


assert dkim.verify(bruto, dnsfunc=dns), "a assinatura DKIM não confere com a chave publicada"
print("ok: DKIM d=example.com confere com a chave publicada")
