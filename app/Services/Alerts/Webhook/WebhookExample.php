<?php

namespace App\Services\Alerts\Webhook;

/**
 * O exemplo da documentação na tela do webhook: um alert.match com dados de
 * mentira, no formato do que chega. O teste de contrato confere que ele tem os
 * mesmos campos do envio de verdade (tests/fixtures/webhook/alert.match.json).
 */
final class WebhookExample
{
    public static function payload(): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $case = '3f6c1e9a-8b2d-4c71-9e05-7d4a2b6c8f13';

        return [
            'version' => MessageBuilder::VERSION,
            'id' => '0f8c6b2e-5d1a-4c3e-9a7b-2f4d6e8a1c3b',
            'event' => 'alert.match',
            'sent_at' => '2026-11-01T12:00:05Z',
            'data' => [
                'title' => 'Falha no FortiOS permite execução remota de código sem login',
                'kind' => 'new_case',
                'criticity' => 'critical',
                'language' => 'pt',
                'summary' => 'A Fortinet corrigiu uma falha no SSL-VPN do FortiOS que permite executar código no equipamento sem autenticação. Há relato de exploração ativa.',
                'case' => [
                    'id' => $case,
                    'title' => 'Falha no FortiOS permite execução remota de código sem login',
                    'criticity' => 'critical',
                    'url' => "{$site}/materia/{$case}",
                    'cves' => ['CVE-2026-24858'],
                    'threat_actors' => [],
                    'sectors' => ['Governo', 'Finanças'],
                    'products' => ['FortiOS 7.4', 'FortiProxy 7.4'],
                    'sources' => 4,
                    'first_seen_at' => '2026-11-01T11:42:10Z',
                    'exploits' => ['EDB-52473'],
                ],
                'match' => [
                    'alerts' => ['Acme Pagamentos'],
                    'cites_company' => false,
                    'terms' => ['Fortinet', 'FortiOS'],
                    'reasons' => ['FortiOS — produto da sua stack afetado'],
                    'score' => 90,
                    'min_criticity' => 'high',
                ],
                'ai_review' => ['relevance' => 92, 'reason' => 'O FortiOS está na sua stack e a falha é explorada sem login.'],
                'links' => [
                    'case' => "{$site}/materia/{$case}",
                    'relevant' => "{$site}/a/1234/relevante?expires=1764590400&signature=…",
                    'irrelevant' => "{$site}/a/1234/nao-relevante?expires=1764590400&signature=…",
                ],
            ],
        ];
    }

    /** O exemplo como o receptor lê: JSON indentado. */
    public static function json(): string
    {
        return json_encode(self::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Como conferir a assinatura e o cabeçalho no servidor do cliente, em cada linguagem: os bytes exatos do
     * corpo, o `t` dentro da janela, o HMAC e o segredo comparados em tempo constante. O WebhookEventsTest
     * roda cada um (o de Node, onde houver node) contra envios assinados pelo Security Monitor, de verdade e adulterados.
     *
     * @return array<string, string> linguagem => código
     */
    public static function verification(): array
    {
        return [
            'PHP' => <<<'PHP'
                <?php
                // Os bytes exatos que chegaram: a assinatura é deles, antes de qualquer json_decode.
                $body = file_get_contents('php://input');
                $secret = getenv('SECMON_WEBHOOK_SECRET');

                // Security-Monitor-Signature: t=1761998405,v1=<hex do HMAC-SHA256 de "<t>.<corpo>">
                $parts = [];
                foreach (explode(',', $_SERVER['HTTP_SECURITY_MONITOR_SIGNATURE'] ?? '') as $item) {
                    [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
                    $parts[$key] = $value;
                }
                $t = $parts['t'] ?? '';

                $valid = ctype_digit($t)
                    && abs(time() - (int) $t) <= 300 // 5 minutos: recusa a repetição de um envio antigo
                    && hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $parts['v1'] ?? '')
                    && hash_equals($secret, $_SERVER['HTTP_SECURITY_MONITOR_NOTIFICATION'] ?? '');

                if (! $valid) {
                    http_response_code(401);
                    exit;
                }

                $event = json_decode($body, true);
                // $event['id'] é único por entrega e se repete nas novas tentativas: guarde e ignore o que já viu.
                http_response_code(204);
                PHP,
            'Node.js (Express)' => <<<'JS'
                const crypto = require('node:crypto');
                const express = require('express');

                const app = express();
                const secret = process.env.SECMON_WEBHOOK_SECRET;
                const same = (a, b) => {
                  const x = Buffer.from(a);
                  const y = Buffer.from(b);
                  return x.length === y.length && crypto.timingSafeEqual(x, y);
                };

                // express.raw: a assinatura é dos bytes exatos do corpo, antes do JSON.parse.
                app.post('/secmon', express.raw({ type: 'application/json' }), (req, res) => {
                  const parts = Object.fromEntries(
                    (req.get('Security-Monitor-Signature') || '').split(',').map((item) => item.trim().split('=', 2)),
                  );
                  const t = parts.t || '';
                  const expected = crypto.createHmac('sha256', secret).update(`${t}.`).update(req.body).digest('hex');

                  const valid = /^\d+$/.test(t)
                    && Math.abs(Date.now() / 1000 - Number(t)) <= 300 // 5 minutos: recusa a repetição de um envio antigo
                    && same(expected, parts.v1 || '')
                    && same(secret, req.get('Security-Monitor-Notification') || '');

                  if (!valid) return res.sendStatus(401);

                  const event = JSON.parse(req.body);
                  // event.id é único por entrega e se repete nas novas tentativas: guarde e ignore o que já viu.
                  res.sendStatus(204);
                });
                JS,
            'Python (Flask)' => <<<'PY'
                import hashlib
                import hmac
                import os
                import time

                from flask import Flask, abort, request

                app = Flask(__name__)
                SECRET = os.environ["SECMON_WEBHOOK_SECRET"]


                @app.post("/secmon")
                def secmon():
                    body = request.get_data()  # os bytes exatos: a assinatura é deles, antes do JSON
                    header = request.headers.get("Security-Monitor-Signature", "")
                    parts = dict(item.strip().split("=", 1) for item in header.split(",") if "=" in item)
                    t = parts.get("t", "")
                    expected = hmac.new(SECRET.encode(), t.encode() + b"." + body, hashlib.sha256).hexdigest()

                    valid = (
                        t.isascii() and t.isdigit()
                        and abs(time.time() - int(t)) <= 300  # 5 minutos: recusa a repetição de um envio antigo
                        and hmac.compare_digest(expected.encode(), parts.get("v1", "").encode())
                        and hmac.compare_digest(SECRET.encode(), request.headers.get("Security-Monitor-Notification", "").encode())
                    )
                    if not valid:
                        abort(401)

                    event = request.get_json()
                    # event["id"] é único por entrega e se repete nas novas tentativas: guarde e ignore o que já viu.
                    return "", 204
                PY,
        ];
    }
}
