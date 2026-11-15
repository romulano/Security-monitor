<?php

namespace App\Services\Alerts\Discord;

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
 * Mensagens em embed do Discord, com a cor da criticidade. Os "botões" são
 * links no texto: funcionam igual pelo bot e pelo webhook colado. Menções
 * ficam desligadas (`allowed_mentions`), então um "@everyone" no título de
 * uma notícia não chama ninguém.
 */
class MessageBuilder extends AlertMessage
{
    private const COLORS = ['critical' => 0xDC2626, 'high' => 0xEA580C, 'medium' => 0xCA8A04, 'low' => 0x64748B];

    private const ACCENT = 0xE3A93B;

    private const OK = 0x22C55E;

    /** Nome vazio (espaço de largura zero): o campo dos links fica sem título. */
    private const BLANK = "\u{200B}";

    public function alert(AlertMatch $match): array
    {
        $case = $match->case;
        $profile = $match->profile;
        $pt = $profile->language !== 'en';
        $level = $this->level($match->criticity);

        if ($match->hasBrandReason()) {
            $brand = collect($match->reasons)->firstWhere('type', 'brand')['label'] ?? $profile->company_name;
            $why = '**Cita a sua empresa:** '.$this->esc((string) $brand);
        } else {
            $why = 'Por que você recebeu: **'.$this->esc($match->reasonTerms(4)).'** · seu nível mínimo é '.ucfirst($profile->min_criticity);
        }

        if ($alerts = $this->alertNames($match)) {
            $why = 'Alerta **'.$this->esc($alerts).'** · '.$why;
        }

        $body = $this->body($match, $pt);
        $update = $match->kind === 'case_update';

        $fields = collect($this->facts($match))
            ->map(fn ($value, $label) => ['name' => $label, 'value' => Str::limit($this->esc($value), 1000), 'inline' => true])
            ->values()
            ->push(['name' => self::BLANK, 'value' => implode(' · ', [
                $this->link('Ler no Security Monitor', route('feed.show', $case->uuid)),
                $this->link('Relevante', URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDays(30), ['match' => $match->id])),
                $this->link('Não é relevante', URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id])),
            ]), 'inline' => false])
            ->all();

        return $this->message("**{$level}** · ".($update ? 'atualização de um caso' : 'novo alerta do Security Monitor'), [[
            'title' => Str::limit(($update ? 'Atualização · ' : '').$this->title($case, $pt), 250),
            'url' => route('feed.show', $case->uuid),
            'color' => $this->color($match->criticity),
            'description' => Str::limit($why.($body ? "\n\n".$this->esc($this->plain($body, 600)) : ''), 4000),
            'fields' => $fields,
            'timestamp' => ($match->matched_at ?? now())->toIso8601String(),
        ]]);
    }

    /** @param  Collection<int, AlertMatch>  $matches */
    public function grouped(Collection $matches, string $reason): array
    {
        $n = $matches->count();
        $intro = $reason === 'quiet_hours'
            ? 'Chegaram durante o seu horário silencioso:'
            : 'O limite de mensagens por hora foi atingido, então juntamos o restante aqui:';

        $list = $this->list($matches->take(10)->map(fn (AlertMatch $m) => [
            'level' => $m->criticity,
            'case' => $m->case,
            'note' => ($m->profile->hasSiblings() ? $m->profile->displayName().': ' : '').$m->reasonTerms(),
            'pt' => $m->profile->language !== 'en',
        ]));

        return $this->message("**{$n} alertas novos** do Security Monitor", [[
            'title' => "{$n} alertas novos",
            'color' => $this->color($this->highest($matches->pluck('criticity')->all())),
            'description' => Str::limit($intro."\n\n".$list.($n > 10 ? "\n…e mais ".($n - 10).'.' : ''), 4000),
        ]]);
    }

    public function digest(array $data): array
    {
        $pt = $data['lang'] !== 'en';
        $date = $data['date']->format('d/m/Y');
        $footer = "Você recebe este resumo {$data['days']}, às {$data['time']} ({$data['tz_label']}).";
        $news = $data['news'] ?? true;
        [$openLabel, $openUrl] = $data['open'] ?? ($news ? ['Abrir o feed', route('feed.index')] : ['Abrir o Radar', route('alerts.lookalikes')]);
        $links = $this->link($openLabel, $openUrl);
        $others = ($data['radar'] ?? []) || ($data['github'] ?? []);

        if (! $news) {
            // Notícias desligadas na Central: o resumo deste canal é só o Radar e o GitHub.
            $description = $others ? '' : 'Nada novo '.($data['quiet_label'] ?? 'no Radar de domínios').' nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.';
        } elseif ($data['total'] === 0) {
            $description = $others ? 'Nenhuma notícia nova nas últimas 24 h.' : 'Nada novo nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.';
        } else {
            $counts = collect(AlertProfile::LEVELS)->map(fn ($l) => ($data['counts'][$l] ?? 0)." {$l}")->implode(' · ');
            $mine = $data['mine'];
            $description = "{$data['total']} casos novos ou atualizados · {$counts}\n\n**Afeta você (".count($mine).")**\n".(
                $mine === [] ? 'Nenhum caso casou com o seu perfil neste período.' : $this->list(collect($mine)->map(fn ($m) => [
                    'level' => $m['criticity'],
                    'case' => $m['case'],
                    'note' => $m['why'].($m['sent_at'] ? ' · já enviado às '.$m['sent_at'] : ''),
                    'pt' => $pt,
                ]))
            );
        }

        $embeds = $description === '' ? [] : [[
            'title' => "Resumo das últimas 24 h · {$date}",
            'color' => self::ACCENT,
            'description' => Str::limit($description, 3500),
        ]];

        if ($news && $data['total'] > 0 && $data['scope'] === 'all' && $data['panorama'] !== []) {
            $embeds[] = [
                'title' => 'Panorama geral · '.count($data['panorama']).' de '.$data['panorama_total'].' critical e high',
                'color' => self::COLORS['high'],
                'description' => Str::limit($this->list(collect($data['panorama'])->map(fn (NewsCase $c) => ['level' => $c->criticity, 'case' => $c, 'note' => null, 'pt' => $pt])), 2000),
            ];
        }

        if ($radar = $data['radar'] ?? []) {
            $levels = array_column($radar, 'level');
            $embeds[] = [
                'title' => 'Radar de domínios ('.count($radar).')',
                'color' => in_array('critical', $levels, true) ? self::COLORS['critical'] : (in_array('high', $levels, true) ? self::COLORS['high'] : self::COLORS['medium']),
                'description' => Str::limit(collect($radar)
                    ->map(fn ($r) => '• **'.$r['label'].'** '.$this->link($r['host'], $r['url']).($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 140)) : ''))
                    ->implode("\n"), 3500),
            ];
        }

        if ($github = $data['github'] ?? []) {
            $levels = array_column($github, 'level');
            $embeds[] = [
                'title' => 'GitHub ('.count($github).')',
                'color' => in_array('critical', $levels, true) ? self::COLORS['critical'] : (in_array('high', $levels, true) ? self::COLORS['high'] : self::COLORS['medium']),
                'description' => Str::limit(collect($github)
                    ->map(fn ($r) => '• **'.$r['label'].'** '.$this->link(Str::limit($r['host'], 90), $r['url']).($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 140)) : ''))
                    ->implode("\n"), 3500),
            ];
        }

        $last = array_key_last($embeds);
        $embeds[$last]['description'] .= "\n\n".$links;
        $embeds[$last]['footer'] = ['text' => $footer];

        return $this->message("**Resumo do Security Monitor** · {$date}", $embeds);
    }

    public function test(NotificationChannel $channel): array
    {
        $where = $channel->method === 'dm' ? 'Esta conversa está conectada' : 'Este canal está conectado';

        return $this->message('**Teste do Security Monitor** ✅', [[
            'color' => self::OK,
            'description' => "{$where} e vai receber os alertas da sua entrevista.\n\n"
                .'Recebe: '.$channel->typesLabel().($channel->isPaused() ? ' (envio pausado)' : '')
                .' · Na hora: '.($channel->realtime ? 'ligado' : 'desligado')
                .' · Resumo: '.($channel->digest ? $channel->digest_time.', '.$channel->daysLabel() : 'desligado'),
        ]]);
    }

    /**
     * O código da reautenticação (ReauthCodes). O histórico de envios guarda
     * esta mesma mensagem com pontos no lugar do código. Com `$domain`, a
     * mensagem diz a ação e o domínio (o aceite de um teste de segurança).
     */
    public function code(string $code, ?string $domain = null): array
    {
        // Texto do Blue Team (rodada 3, item 1): o domínio vai entre crases, nunca em texto corrido.
        $code = str_replace('`', '', $code);
        $head = $domain !== null
            ? '🔐 **Código para confirmar uma ação de teste de segurança no Security Monitor.** Domínio: `'.str_replace(['*', '_', '`', '~', '|', '\\'], '', $domain).'`. Código: `'.$code.'`'
            : '🔐 **Código de confirmação do Security Monitor:** `'.$code.'`';

        return $this->message($head, [[
            'color' => self::ACCENT,
            'description' => "Vale por 10 minutos e uma vez só, na página do Security Monitor que pediu.\n"
                .'Não passe este código a ninguém: o Security Monitor nunca pede o código por mensagem. '
                .'Se não foi você que pediu, troque a sua senha.',
        ]]);
    }

    /**
     * O aviso de que os testes de segurança ligaram (Blue Team, rodada 3, item 3): o
     * texto do Blue Team, com o domínio entre crases (nunca vira link) e o link da área entre < >, que o Discord
     * mostra sem a prévia (a do login, para quem não está logado), como o Telegram.
     *
     * @param  list<string>  $domains
     */
    public function testsReady(array $domains, string $url): array
    {
        return $this->message(PentestReadyNotice::text($domains, fn (string $domain) => '`'.str_replace(['*', '_', '`', '~', '|', '\\'], '', $domain).'`').' <'.$url.'>', []);
    }

    private function message(string $content, array $embeds): array
    {
        return ['content' => $content, 'embeds' => $embeds, 'allowed_mentions' => ['parse' => []]];
    }

    private function list(Collection $items): string
    {
        return $items->map(function (array $item) {
            $title = Str::limit($this->title($item['case'], $item['pt']), 90);
            $note = $item['note'] ? ' — '.$this->esc($item['note']) : '';

            return '• **'.$this->level($item['level']).'** '.$this->link($title, route('feed.show', $item['case']->uuid)).$note;
        })->implode("\n");
    }

    /** Link mascarado: colchetes no texto quebrariam o link. */
    private function link(string $label, string $url): string
    {
        return '['.str_replace(['[', ']'], ['(', ')'], $label).']('.$url.')';
    }

    private function color(?string $criticity): int
    {
        return self::COLORS[$criticity] ?? self::COLORS['low'];
    }

    private function highest(array $levels): ?string
    {
        foreach (array_keys(self::COLORS) as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return null;
    }

    /** Texto das notícias sem virar formatação nem link do Discord. */
    private function esc(string $text): string
    {
        return preg_replace('/([\\\\*_~`|>\[\]])/', '\\\\$1', $text);
    }
}
