<?php

namespace App\Services\Alerts\Slack;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Pentest\PentestReadyNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Mensagens em Block Kit. Os botões são links (não interatividade do Slack),
 * então funcionam igual no webhook colado e no app instalado.
 */
class MessageBuilder extends AlertMessage
{
    public function alert(AlertMatch $match): array
    {
        $case = $match->case;
        $profile = $match->profile;
        $pt = $profile->language !== 'en';
        $title = $this->title($case, $pt);
        $level = $this->level($match->criticity);

        if ($match->hasBrandReason()) {
            $brand = collect($match->reasons)->firstWhere('type', 'brand')['label'] ?? $profile->company_name;
            $why = "*Cita a sua empresa:* {$this->esc($brand)}";
        } else {
            $why = 'Por que você recebeu: *'.$this->esc($match->reasonTerms(4)).'* · seu nível mínimo é '.ucfirst($profile->min_criticity);
        }

        // Cliente com mais de um alerta (produtos, sites): diz qual casou.
        if ($alerts = $this->alertNames($match)) {
            $why = 'Alerta *'.$this->esc($alerts).'* · '.$why;
        }

        $body = $this->body($match, $pt);
        $facts = $this->facts($match);
        $fields = array_map(fn ($label, $value) => $this->field($label, $value), array_keys($facts), $facts);

        $prefix = $match->kind === 'case_update' ? 'Atualização · ' : '';

        return [
            'text' => "[{$level}] {$prefix}{$title}",
            'blocks' => array_values(array_filter([
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => Str::limit("{$level} · {$prefix}{$title}", 147), 'emoji' => false]],
                $this->context($why),
                $body ? ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->esc($this->plain($body, 600))]] : null,
                $fields ? ['type' => 'section', 'fields' => array_values($fields)] : null,
                ['type' => 'actions', 'elements' => [
                    $this->button('Ler no Security Monitor', route('alerts.read', $case->uuid), 'primary'),
                    $this->button('Relevante', URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDays(30), ['match' => $match->id])),
                    $this->button('Não é relevante', URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id])),
                ]],
            ])),
        ];
    }

    /** @param  Collection<int, AlertMatch>  $matches */
    public function grouped(Collection $matches, string $reason): array
    {
        $n = $matches->count();
        $intro = $reason === 'quiet_hours'
            ? 'Chegaram durante o seu horário silencioso:'
            : 'O limite de mensagens por hora foi atingido, então juntamos o restante aqui:';

        return [
            'text' => "{$n} alertas do Security Monitor",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "{$n} alertas novos", 'emoji' => false]],
                $this->context($intro),
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->list($matches->take(10)->map(fn (AlertMatch $m) => [
                    'level' => $m->criticity,
                    'case' => $m->case,
                    'note' => ($m->profile->hasSiblings() ? $m->profile->displayName().': ' : '').$m->reasonTerms(),
                    'pt' => $m->profile->language !== 'en',
                ]))."\n".($n > 10 ? '…e mais '.($n - 10).'.' : '')]],
            ],
        ];
    }

    public function digest(array $data): array
    {
        $pt = $data['lang'] !== 'en';
        $date = $data['date']->format('d/m/Y');
        $counts = collect(AlertProfile::LEVELS)
            ->map(fn ($l) => ($data['counts'][$l] ?? 0)." {$l}")
            ->implode(' · ');

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "Resumo das últimas 24 h · {$date}", 'emoji' => false]],
        ];

        $others = ($data['radar'] ?? []) || ($data['github'] ?? []);

        if (! ($data['news'] ?? true)) {
            // Notícias desligadas na Central: o resumo deste canal é só o Radar e o GitHub.
            if (! $others) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Nada novo '.($data['quiet_label'] ?? 'no Radar de domínios').' nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.']];
            }
        } elseif ($data['total'] === 0) {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $others ? 'Nenhuma notícia nova nas últimas 24 h.' : 'Nada novo nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.']];
        } else {
            $blocks[] = $this->context("{$data['total']} casos novos ou atualizados · {$counts}");

            $mine = $data['mine'];
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Afeta você ('.count($mine).')*'."\n".(
                $mine === [] ? 'Nenhum caso casou com o seu perfil neste período.' : $this->list(collect($mine)->map(fn ($m) => [
                    'level' => $m['criticity'],
                    'case' => $m['case'],
                    'note' => $m['why'].($m['sent_at'] ? ' · já enviado às '.$m['sent_at'] : ''),
                    'pt' => $pt,
                ]))
            )]];

            if ($data['scope'] === 'all' && $data['panorama'] !== []) {
                $blocks[] = ['type' => 'divider'];
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Panorama geral · '.count($data['panorama']).' de '.$data['panorama_total'].' critical e high*'."\n".
                    $this->list(collect($data['panorama'])->map(fn (NewsCase $c) => ['level' => $c->criticity, 'case' => $c, 'note' => null, 'pt' => $pt]))]];
            }
        }

        if ($radar = $data['radar'] ?? []) {
            if (count($blocks) > 1) {
                $blocks[] = ['type' => 'divider'];
            }

            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Radar de domínios ('.count($radar).')*'."\n".collect($radar)
                ->map(fn ($r) => '• *'.$r['label'].'* <'.$r['url'].'|'.$this->esc($r['host']).'>'.($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 140)) : ''))
                ->implode("\n")]];
        }

        if ($github = $data['github'] ?? []) {
            if (count($blocks) > 1) {
                $blocks[] = ['type' => 'divider'];
            }

            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*GitHub ('.count($github).')*'."\n".collect($github)
                ->map(fn ($r) => '• *'.$r['label'].'* <'.$r['url'].'|'.$this->esc(Str::limit($r['host'], 90)).'>'.($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 140)) : ''))
                ->implode("\n")]];
        }

        [$openLabel, $openUrl] = $data['open'] ?? (($data['news'] ?? true) ? ['Abrir o feed', route('feed.index')] : ['Abrir o Radar', route('alerts.lookalikes')]);

        $blocks[] = ['type' => 'actions', 'elements' => [
            $this->button($openLabel, $openUrl, 'primary'),
        ]];
        $blocks[] = $this->context("Você recebe este resumo {$data['days']}, às {$data['time']} ({$data['tz_label']}).");

        return ['text' => "Resumo do Security Monitor · {$date}", 'blocks' => $blocks];
    }

    public function test(NotificationChannel $channel): array
    {
        return [
            'text' => 'Teste do Security Monitor: este canal está conectado.',
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Teste do Security Monitor* :white_check_mark:\nEste canal está conectado e vai receber os alertas da sua entrevista."]],
                $this->context('Recebe: '.$channel->typesLabel().($channel->isPaused() ? ' (envio pausado)' : '').' · Na hora: '.($channel->realtime ? 'ligado' : 'desligado').' · Resumo: '.($channel->digest ? $channel->digest_time.', '.$channel->daysLabel() : 'desligado')),
            ],
        ];
    }

    /**
     * O aviso de que os testes de segurança ligaram (Blue Team, rodada 3, item 3): o
     * texto do Blue Team, com o domínio entre crases (nunca vira link) e o link da área.
     *
     * @param  list<string>  $domains
     */
    public function testsReady(array $domains, string $url): array
    {
        $text = PentestReadyNotice::text($domains, fn (string $domain) => '`'.str_replace('`', '', $domain).'`');

        return [
            'text' => PentestReadyNotice::text($domains).' '.$url,
            'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->esc($text).' '.$url]]],
        ];
    }

    private function list(Collection $items): string
    {
        return $items->map(function (array $item) {
            $title = $this->title($item['case'], $item['pt']);
            $url = route('alerts.read', $item['case']->uuid);
            $note = $item['note'] ? ' — '.$this->esc($item['note']) : '';

            return '• *'.$this->level($item['level']).'* <'.$url.'|'.$this->esc(Str::limit($title, 110)).'>'.$note;
        })->implode("\n");
    }

    private function context(string $mrkdwn): array
    {
        return ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $mrkdwn]]];
    }

    private function field(string $label, string $value): array
    {
        return ['type' => 'mrkdwn', 'text' => "*{$label}*\n".$this->esc($value)];
    }

    private function button(string $label, string $url, ?string $style = null): array
    {
        return array_filter(['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $label], 'url' => $url, 'style' => $style]);
    }

    /** O Slack só exige escapar &, < e > no mrkdwn. */
    private function esc(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
