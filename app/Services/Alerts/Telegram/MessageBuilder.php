<?php

namespace App\Services\Alerts\Telegram;

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
 * Mensagens do Telegram em HTML, com a criticidade num emoji (o Telegram não
 * tem cor) e os links em botões embaixo da mensagem. O texto tem teto de 4096
 * caracteres: as listas param antes e dizem quantos ficaram de fora.
 */
class MessageBuilder extends AlertMessage
{
    private const ICONS = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🟡', 'low' => '⚪'];

    public function alert(AlertMatch $match): array
    {
        $case = $match->case;
        $profile = $match->profile;
        $pt = $profile->language !== 'en';
        $update = $match->kind === 'case_update';

        if ($match->hasBrandReason()) {
            $brand = collect($match->reasons)->firstWhere('type', 'brand')['label'] ?? $profile->company_name;
            $why = '<b>Cita a sua empresa:</b> '.$this->esc((string) $brand);
        } else {
            $why = 'Por que você recebeu: <b>'.$this->esc($match->reasonTerms(4)).'</b> · seu nível mínimo é '.ucfirst($profile->min_criticity);
        }

        if ($alerts = $this->alertNames($match)) {
            $why = 'Alerta <b>'.$this->esc($alerts).'</b> · '.$why;
        }

        $body = $this->body($match, $pt);
        $facts = collect($this->facts($match))->map(fn ($value, $label) => "<b>{$label}:</b> ".$this->esc(Str::limit($value, 300)));

        $text = $this->icon($match->criticity).' <b>'.$this->level($match->criticity).'</b> · '.($update ? 'atualização de um caso' : 'novo alerta do Security Monitor')
            ."\n\n<b>".$this->esc(Str::limit(($update ? 'Atualização · ' : '').$this->title($case, $pt), 250)).'</b>'
            ."\n\n".$why
            .($body ? "\n\n".$this->esc($this->plain($body, 600)) : '')
            .($facts->isNotEmpty() ? "\n\n".$facts->implode("\n") : '');

        return $this->message($text, [
            [$this->button('Ler no Security Monitor', route('feed.show', $case->uuid))],
            [
                $this->button('👍 Relevante', URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDays(30), ['match' => $match->id])),
                $this->button('👎 Não é relevante', URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id])),
            ],
        ]);
    }

    /** @param  Collection<int, AlertMatch>  $matches */
    public function grouped(Collection $matches, string $reason): array
    {
        $n = $matches->count();
        $intro = $reason === 'quiet_hours'
            ? 'Chegaram durante o seu horário silencioso:'
            : 'O limite de mensagens por hora foi atingido, então juntamos o restante aqui:';

        $list = $this->list($matches->map(fn (AlertMatch $m) => [
            'level' => $m->criticity,
            'case' => $m->case,
            'note' => ($m->profile->hasSiblings() ? $m->profile->displayName().': ' : '').$m->reasonTerms(),
            'pt' => $m->profile->language !== 'en',
        ]), 3400);

        return $this->message("🔔 <b>{$n} alertas novos</b> do Security Monitor\n\n{$intro}\n\n{$list}");
    }

    public function digest(array $data): array
    {
        $pt = $data['lang'] !== 'en';
        $text = '📰 <b>Resumo do Security Monitor</b> · '.$data['date']->format('d/m/Y')."\n\n";

        $news = $data['news'] ?? true;

        $others = ($data['radar'] ?? []) || ($data['github'] ?? []);

        if (! $news) {
            // Notícias desligadas na Central: o resumo deste canal é só o Radar e o GitHub.
            $text .= $others ? '' : 'Nada novo '.($data['quiet_label'] ?? 'no Radar de domínios').' nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.';
        } elseif ($data['total'] === 0) {
            $text .= $others ? 'Nenhuma notícia nova nas últimas 24 h.' : 'Nada novo nas últimas 24 h. O Security Monitor está de olho e avisa quando aparecer algo.';
        } else {
            $counts = collect(AlertProfile::LEVELS)->map(fn ($l) => ($data['counts'][$l] ?? 0)." {$l}")->implode(' · ');
            $mine = $data['mine'];
            $text .= "{$data['total']} casos novos ou atualizados · {$counts}\n\n<b>Afeta você (".count($mine).")</b>\n".(
                $mine === [] ? 'Nenhum caso casou com o seu perfil neste período.' : $this->list(collect($mine)->map(fn ($m) => [
                    'level' => $m['criticity'],
                    'case' => $m['case'],
                    'note' => $m['why'].($m['sent_at'] ? ' · já enviado às '.$m['sent_at'] : ''),
                    'pt' => $pt,
                ]), 2000)
            );

            if ($data['scope'] === 'all' && $data['panorama'] !== []) {
                $text .= "\n\n<b>Panorama geral · ".count($data['panorama']).' de '.$data['panorama_total']." critical e high</b>\n"
                    .$this->list(collect($data['panorama'])->map(fn (NewsCase $c) => ['level' => $c->criticity, 'case' => $c, 'note' => null, 'pt' => $pt]), 1200);
            }
        }

        if ($radar = $data['radar'] ?? []) {
            $icons = ['critical' => '🔴', 'high' => '🟠', 'watch' => '🟡'];
            $text .= ($news ? "\n\n" : '').'<b>Radar de domínios ('.count($radar).")</b>\n".collect($radar)
                ->map(fn ($r) => ($icons[$r['level']] ?? '⚪').' <a href="'.$this->esc($r['url']).'">'.$this->esc($r['host']).'</a>'.($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 120)) : ''))
                ->implode("\n");
        }

        if ($github = $data['github'] ?? []) {
            $icons = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🔵'];
            $text .= ($news || ($data['radar'] ?? []) ? "\n\n" : '').'<b>GitHub ('.count($github).")</b>\n".collect($github)
                ->map(fn ($r) => ($icons[$r['level']] ?? '⚪').' <a href="'.$this->esc($r['url']).'">'.$this->esc(Str::limit($r['host'], 90)).'</a>'.($r['note'] ? ' — '.$this->esc(Str::limit($r['note'], 120)) : ''))
                ->implode("\n");
        }

        $text .= "\n\n<i>Você recebe este resumo {$data['days']}, às {$data['time']} ({$data['tz_label']}).</i>";
        [$openLabel, $openUrl] = $data['open'] ?? ($news ? ['Abrir o feed', route('feed.index')] : ['Abrir o Radar', route('alerts.lookalikes')]);

        return $this->message($text, [[$this->button($openLabel, $openUrl)]]);
    }

    public function test(NotificationChannel $channel): array
    {
        $where = $channel->method === 'group' ? 'Este grupo está conectado' : 'Esta conversa está conectada';

        return $this->message("✅ <b>Teste do Security Monitor</b>\n\n{$where} e vai receber os alertas da sua entrevista.\n\n"
            .'Recebe: '.$channel->typesLabel().($channel->isPaused() ? ' (envio pausado)' : '')
            .' · Na hora: '.($channel->realtime ? 'ligado' : 'desligado')
            .' · Resumo: '.($channel->digest ? $channel->digest_time.', '.$channel->daysLabel() : 'desligado'));
    }

    /**
     * O código da reautenticação (ReauthCodes). O histórico de envios guarda
     * esta mesma mensagem com pontos no lugar do código. Com `$domain`, a
     * mensagem diz a ação e o domínio (o aceite de um teste de segurança).
     */
    public function code(string $code, ?string $domain = null): array
    {
        // Texto do Blue Team (rodada 3, item 1): o domínio vai em <code>, nunca em texto corrido,
        // para o Telegram não o transformar em link (A2 do Red Team).
        $head = $domain !== null
            ? "🔐 <b>Código para confirmar uma ação de teste de segurança no Security Monitor.</b> Domínio: <code>{$this->esc($domain)}</code>. Código: <code>{$this->esc($code)}</code>"
            : "🔐 <b>Código de confirmação do Security Monitor:</b> <code>{$this->esc($code)}</code>";

        return $this->message($head."\n\n"
            .'Vale por 10 minutos e uma vez só, na página do Security Monitor que pediu. '
            .'Não passe este código a ninguém: o Security Monitor nunca pede o código por mensagem. '
            .'Se não foi você que pediu, troque a sua senha.');
    }

    /**
     * O aviso de que os testes de segurança ligaram (Blue Team, rodada 3, item 3): o
     * texto do Blue Team, com o domínio em <code> (nunca vira link, A2 do Red Team) e o
     * link da área.
     *
     * @param  list<string>  $domains
     */
    public function testsReady(array $domains, string $url): array
    {
        return $this->message(PentestReadyNotice::text($domains, fn (string $domain) => '<code>'.$this->esc($domain).'</code>').' '.$this->esc($url));
    }

    /** Texto das notícias sem virar marcação do Telegram. */
    public function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Sem botões, a mensagem vai sem o teclado. */
    private function message(string $text, array $keyboard = []): array
    {
        return [
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ] + ($keyboard ? ['reply_markup' => ['inline_keyboard' => $keyboard]] : []);
    }

    private function button(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    /** Lista de casos até `$budget` caracteres; o que não cabe vira "…e mais N". */
    private function list(Collection $items, int $budget): string
    {
        $lines = [];
        $used = 0;

        foreach ($items->values() as $i => $item) {
            $title = Str::limit($this->title($item['case'], $item['pt']), 90);
            $note = $item['note'] ? ' — '.$this->esc(Str::limit($item['note'], 160)) : '';
            $line = '• '.$this->icon($item['level']).' <b>'.$this->level($item['level']).'</b> '
                .'<a href="'.$this->esc(route('feed.show', $item['case']->uuid)).'">'.$this->esc($title).'</a>'.$note;

            if ($i >= 10 || $used + mb_strlen($line) > $budget) {
                $lines[] = '…e mais '.($items->count() - $i).'.';

                break;
            }

            $lines[] = $line;
            $used += mb_strlen($line) + 1;
        }

        return implode("\n", $lines);
    }

    private function icon(?string $criticity): string
    {
        return self::ICONS[$criticity] ?? self::ICONS['low'];
    }
}
