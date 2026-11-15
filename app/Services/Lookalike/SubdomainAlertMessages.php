<?php

namespace App\Services\Lookalike;

use App\Models\NotificationChannel;
use App\Models\SubdomainAlert;
use App\Services\Alerts\Webhook\MessageBuilder as WebhookMessages;
use Illuminate\Support\Str;

/**
 * O aviso de um subdomínio vigiado pelo Radar (E4a) em cada app: o que mudou no DNS, o que fazer e o link do Radar.
 * Sem botões de parecer: não é um domínio de terceiro, é o DNS da própria empresa.
 */
class SubdomainAlertMessages
{
    private const ICONS = ['critical' => '🔴', 'high' => '🟠'];

    private const COLORS = ['critical' => 0xDC2626, 'high' => 0xEA580C];

    public function alert(SubdomainAlert $alert, NotificationChannel $channel): array
    {
        $p = $this->parts($alert);

        return match ($channel->type) {
            'discord' => [
                'content' => "**{$p['level']}** · Radar de domínios",
                'embeds' => [[
                    'title' => Str::limit($p['title'], 250),
                    'url' => $p['link'],
                    'color' => self::COLORS[$alert->risk] ?? self::COLORS['high'],
                    'description' => Str::limit($p['summary']."\n\n".$p['action']."\n\n[Ver o Radar no Security Monitor]({$p['link']})", 4000),
                    'timestamp' => now()->toIso8601String(),
                ]],
                'allowed_mentions' => ['parse' => []],
            ],
            'telegram' => [
                'text' => (self::ICONS[$alert->risk] ?? '🟠').' <b>'.$p['level'].'</b> · Radar de domínios'
                    ."\n\n<b>".e($p['title']).'</b>'."\n\n".e($p['summary'])."\n\n".e($p['action']),
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => [[['text' => 'Ver o Radar no Security Monitor', 'url' => $p['link']]]]],
            ],
            'webhook' => WebhookMessages::envelope('radar.subdomain', [
                'title' => $p['title'],
                'risk' => $alert->risk,
                'kind' => $alert->kind,
                'subdomain' => $p['name'],
                'watched_domain' => $p['domain'],
                'cname' => $alert->facts['cname'] ?? [],
                'summary' => $p['summary'],
                'links' => ['view' => $p['link']],
            ]),
            default => [
                'text' => "[{$p['level']}] Radar de domínios: {$p['title']}",
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => Str::limit($p['level'].' · '.$p['title'], 147), 'emoji' => false]],
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::slack($p['summary'])."\n\n".self::slack($p['action'])]],
                    ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Ver o Radar no Security Monitor'], 'url' => $p['link'], 'style' => 'primary']]],
                ],
            ],
        };
    }

    /** As partes, iguais em qualquer app. */
    public function parts(SubdomainAlert $alert): array
    {
        $name = $alert->subdomain->name;
        $domain = $alert->subdomain->ownedDomain->domain;
        // O destino que não resolve é o fim da cadeia, e o nome dele vem do DNS de terceiro: só vai à mensagem se for um
        // nome de host (nada de Markdown ou HTML no Discord, no Slack ou no Telegram; M2 do Red Team da E4a).
        $chain = array_values(array_filter((array) ($alert->facts['cname'] ?? []), 'is_string'));
        $target = end($chain) ?: null;
        $target = $target !== null && preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $target) ? $target : 'um destino fora do domínio';
        $takeover = $alert->kind === 'takeover';

        return [
            'level' => $takeover ? 'CRÍTICO' : 'ALTO',
            'name' => $name,
            'domain' => $domain,
            'title' => $takeover ? "{$name} aponta para um destino que não existe" : "{$name} passou a apontar para fora de {$domain}",
            'summary' => $takeover
                ? "O CNAME de {$name} aponta para {$target}, que não resolve. Quem criar esse destino (ou o recurso no serviço dele) passa a responder por {$name}: é a tomada de subdomínio, usada para golpes com o nome da empresa."
                : "Antes, {$name} ficava na zona de {$domain}. Agora ".($alert->subdomain->reasonLabel() ?? 'aponta para fora').'.',
            'action' => $takeover
                ? "Apague o CNAME de {$name} ou recrie o destino. Se o serviço não é mais usado, apagar o registro basta."
                : 'Se não foi a sua equipe que mudou, confira o DNS do domínio: quem controla o destino controla o que aparece nesse endereço.',
            'link' => route('alerts.lookalikes', $alert->watch->urlParams()),
        ];
    }

    private static function slack(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
