<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\NotificationChannel;
use App\Services\Alerts\Webhook\MessageBuilder as WebhookMessages;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * As mensagens de um aviso do Radar, no formato de cada app e no padrão dos
 * alertas de notícia: nível no topo, por que chegou, o parecer da IA, os fatos
 * e os botões. Vai uma foto só (AlertPhoto escolhe qual), por um link assinado
 * que vale 30 dias: Slack, Discord, Telegram e o webhook buscam a imagem sem login.
 */
class AlertMessages
{
    private const ICONS = ['critical' => '🔴', 'high' => '🟠', 'watch' => '🟡', 'low' => '⚪'];

    private const COLORS = ['critical' => 0xDC2626, 'high' => 0xEA580C, 'watch' => 0xCA8A04, 'low' => 0x64748B];

    public function alert(LookalikeAlert $alert, NotificationChannel $channel): array
    {
        return match ($channel->type) {
            'discord' => $this->discord($alert),
            'telegram' => $this->telegram($alert),
            'webhook' => $this->webhook($alert),
            default => $this->slack($alert),
        };
    }

    /** O aviso em JSON (evento radar.alert): as mesmas partes, em campos. */
    public function webhook(LookalikeAlert $alert): array
    {
        $p = $this->parts($alert);
        $lookalike = $alert->lookalike;

        return WebhookMessages::envelope('radar.alert', [
            'title' => $p['title'],
            'risk' => $alert->risk,
            'kind' => $alert->kind,
            'previous_risk' => $alert->kind === 'raised' ? $alert->previous_risk : null,
            'domain' => $p['host'],
            'watched_domain' => $lookalike->watch->domain->domain,
            'summary' => $p['summary'],
            'ai_review' => $alert->ai_score !== null ? ['relevance' => (int) $alert->ai_score, 'reason' => $alert->ai_reason] : null,
            'registered_at' => $lookalike->registered_at?->toDateString(),
            'registrar' => $lookalike->rdap['registrar'] ?? null,
            'receives_email' => $lookalike->hasMx(),
            'hosting' => $lookalike->hosting['org'] ?? $lookalike->hosting['network'] ?? null,
            'photo_url' => $p['photo'],
            'links' => $p['links'],
        ]);
    }

    /** As partes da mensagem, iguais em qualquer app. */
    public function parts(LookalikeAlert $alert): array
    {
        $lookalike = $alert->lookalike;
        $watch = $lookalike->watch;
        $level = mb_strtoupper($lookalike::RISK_LABELS[$alert->risk] ?? $alert->risk);
        $host = $lookalike->displayHost();
        $capture = $alert->capture;
        $photoDevice = $alert->photo['device'] ?? null;

        $context = collect([
            'parecido com '.$watch->domain->domain,
            $alert->kind === 'raised' && $alert->previous_risk
                ? 'era '.(LookalikeDomain::RISK_LABELS[$alert->previous_risk] ?? $alert->previous_risk).', virou '.($lookalike::RISK_LABELS[$alert->risk] ?? $alert->risk)
                : 'apareceu agora',
        ])->filter()->implode(' · ');

        $summary = $alert->ai_reason && $alert->ai_score !== null
            ? $alert->ai_reason
            : collect($lookalike->signals ?? [])->where('points', '>', 0)->sortByDesc('points')->take(3)->pluck('label')->implode('; ').'.';

        return [
            'level' => $level,
            'title' => "{$level} · Radar de domínios: {$host}",
            'host' => $host,
            'context' => $context,
            'summary' => $summary,
            'ai' => $alert->ai_score !== null ? "IA {$alert->ai_score}%" : null,
            'facts' => array_filter([
                'Registrado' => $lookalike->registered_at
                    ? $lookalike->registered_at->format('d/m/Y').(! empty($lookalike->rdap['registrar']) ? ' · '.$lookalike->rdap['registrar'] : '')
                    : null,
                'E-mail' => $lookalike->hasMx() ? 'recebe (MX configurado)' : 'não recebe',
                'Hospedagem' => $lookalike->hosting['org'] ?? $lookalike->hosting['network'] ?? null,
                'Foto' => $photoDevice ? LookalikeCapture::DEVICE_HINTS[$photoDevice].($photoDevice !== 'desktop' ? ' (o golpe aparece no celular)' : '') : null,
            ]),
            'photo' => $this->photoUrl($alert),
            'links' => [
                'view' => route('alerts.lookalikes.show', $lookalike),
                'kit' => route('alerts.lookalikes.kit', $lookalike),
                'ours' => $this->verdictUrl($lookalike, 'nosso'),
                'safe' => $this->verdictUrl($lookalike, 'seguro'),
                'threat' => $this->verdictUrl($lookalike, 'golpe'),
            ],
            'risk' => $alert->risk,
            'captured' => $capture?->captured_at?->timezone('America/Sao_Paulo')->format('d/m H:i'),
        ];
    }

    public function slack(LookalikeAlert $alert): array
    {
        $p = $this->parts($alert);
        $esc = fn (string $t) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $t);
        $button = fn (string $label, string $url, ?string $style = null) => array_filter(['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $label], 'url' => $url, 'style' => $style]);

        return [
            'text' => "[{$p['level']}] Radar de domínios: {$p['host']}",
            'blocks' => array_values(array_filter([
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => Str::limit($p['title'], 147), 'emoji' => false]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $esc($p['context'])]]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => ($p['ai'] ? '*'.$p['ai'].'* · ' : '').$esc($p['summary'])]],
                $p['photo'] ? ['type' => 'image', 'image_url' => $p['photo'], 'alt_text' => 'Print de '.$p['host']] : null,
                $p['facts'] ? ['type' => 'section', 'fields' => array_values(array_map(fn ($label, $value) => ['type' => 'mrkdwn', 'text' => "*{$label}*\n".$esc($value)], array_keys($p['facts']), $p['facts']))] : null,
                ['type' => 'actions', 'elements' => [
                    $button('Ver no Security Monitor', $p['links']['view'], 'primary'),
                    $button('Kit de denúncia', $p['links']['kit']),
                    $button('É golpe', $p['links']['threat'], 'danger'),
                    $button('É nosso', $p['links']['ours']),
                    $button('Não é ameaça', $p['links']['safe']),
                ]],
            ])),
        ];
    }

    public function discord(LookalikeAlert $alert): array
    {
        $p = $this->parts($alert);
        $esc = fn (string $t) => preg_replace('/([\\\\*_~`|>\[\]])/', '\\\\$1', $t);
        $link = fn (string $label, string $url) => '['.$label.']('.$url.')';

        $fields = collect($p['facts'])->map(fn ($value, $label) => ['name' => $label, 'value' => Str::limit($esc($value), 1000), 'inline' => true])->values()->push([
            'name' => "\u{200B}",
            'value' => implode(' · ', [
                $link('Ver no Security Monitor', $p['links']['view']),
                $link('Kit de denúncia', $p['links']['kit']),
                $link('É golpe', $p['links']['threat']),
                $link('É nosso', $p['links']['ours']),
                $link('Não é ameaça', $p['links']['safe']),
            ]),
            'inline' => false,
        ])->all();

        return [
            'content' => "**{$p['level']}** · Radar de domínios",
            'embeds' => [array_filter([
                'title' => Str::limit($p['host'], 250),
                'url' => $p['links']['view'],
                'color' => self::COLORS[$p['risk']] ?? self::COLORS['low'],
                'description' => Str::limit($esc($p['context'])."\n\n".($p['ai'] ? '**'.$p['ai'].'** · ' : '').$esc($p['summary']), 4000),
                'fields' => $fields,
                'image' => $p['photo'] ? ['url' => $p['photo']] : null,
                'timestamp' => now()->toIso8601String(),
            ])],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /** Foto com legenda (até 1.024 caracteres) e os botões embaixo; sem foto, mensagem de texto. */
    public function telegram(LookalikeAlert $alert): array
    {
        $p = $this->parts($alert);
        $esc = fn (string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $facts = collect($p['facts'])->map(fn ($value, $label) => "<b>{$label}:</b> ".$esc(Str::limit($value, 120)))->implode("\n");

        $text = (self::ICONS[$p['risk']] ?? '⚪').' <b>'.$p['level'].'</b> · Radar de domínios'
            ."\n\n<b>".$esc($p['host']).'</b>'
            ."\n".$esc($p['context'])
            ."\n\n".($p['ai'] ? '<b>'.$p['ai'].'</b> · ' : '').$esc(Str::limit($p['summary'], 300))
            .($facts !== '' ? "\n\n".$facts : '');

        $keyboard = ['inline_keyboard' => [
            [['text' => 'Ver no Security Monitor', 'url' => $p['links']['view']]],
            [['text' => '🧰 Kit de denúncia', 'url' => $p['links']['kit']]],
            [['text' => '🚨 É golpe', 'url' => $p['links']['threat']], ['text' => '✅ É nosso', 'url' => $p['links']['ours']], ['text' => '👍 Não é ameaça', 'url' => $p['links']['safe']]],
        ]];

        if ($p['photo']) {
            return ['photo' => $p['photo'], 'caption' => Str::limit($text, 1020), 'parse_mode' => 'HTML', 'reply_markup' => $keyboard];
        }

        return ['text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true], 'reply_markup' => $keyboard];
    }

    private function photoUrl(LookalikeAlert $alert): ?string
    {
        return ! empty($alert->photo['path']) ? URL::temporarySignedRoute('lookalikes.photo', now()->addDays(30), ['alert' => $alert->id]) : null;
    }

    private function verdictUrl(LookalikeDomain $lookalike, string $acao): string
    {
        return URL::temporarySignedRoute('lookalikes.verdict.signed', now()->addDays(30), ['lookalike' => $lookalike->id, 'acao' => $acao]);
    }
}
