<?php

namespace App\Services\Github;

use App\Models\GithubFinding;
use App\Models\NotificationChannel;
use App\Services\Alerts\Webhook\MessageBuilder as WebhookMessages;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * As mensagens de um achado do GitHub no formato de cada app, no padrão dos
 * outros avisos: nível no topo, o que foi achado (com os segredos
 * mascarados), o parecer da IA, o que fazer e os botões de avaliação, que
 * abrem um link assinado (sem login) e ensinam a IA.
 */
class GithubMessages
{
    private const ICONS = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🔵', 'low' => '⚪'];

    private const COLORS = ['critical' => 0xDC2626, 'high' => 0xEA580C, 'medium' => 0x0284C7, 'low' => 0x64748B];

    public function alert(GithubFinding $finding, NotificationChannel $channel): array
    {
        return match ($channel->type) {
            'discord' => $this->discord($finding),
            'telegram' => $this->telegram($finding),
            'webhook' => $this->webhook($finding),
            default => $this->slack($finding),
        };
    }

    /** O achado em JSON (evento github.finding): as mesmas partes, em campos; os valores continuam mascarados. */
    public function webhook(GithubFinding $finding): array
    {
        $p = $this->parts($finding);

        return WebhookMessages::envelope('github.finding', [
            'title' => $p['title'],
            'level' => $p['risk'],
            'kind' => $finding->kind,
            'kind_label' => $finding->kindLabel(),
            'location' => $p['location'],
            'repository' => $finding->repo,
            'path' => $finding->path,
            'fork' => (bool) $finding->repo_fork,
            'own_repository' => $finding->monitor->owns($finding->repo),
            'terms' => array_values($finding->terms ?? []),
            'summary' => $p['summary'],
            'action' => $p['action'],
            'secrets' => collect($finding->secrets ?? [])->where('kind', '!=', 'reference')->take(3)
                ->map(fn ($s) => ['name' => $s['name'], 'masked' => $s['masked'], 'length' => (int) $s['length'], 'looks_real' => (bool) $s['looks_real']])
                ->values()->all(),
            'code' => $p['code'],
            'triage' => $finding->triage_score !== null ? ['confidence' => (int) $finding->triage_score, 'reason' => $finding->triage_reason] : null,
            'ai_review' => $finding->ai_score !== null ? ['relevance' => (int) $finding->ai_score, 'reason' => $finding->ai_reason] : null,
            'links' => $p['links'],
        ]);
    }

    /** As partes da mensagem, iguais em qualquer app. */
    public function parts(GithubFinding $finding): array
    {
        $level = $finding->level();
        $label = mb_strtoupper($level);
        $own = $finding->monitor->owns($finding->repo);

        $values = collect($finding->secrets ?? [])
            ->where('kind', '!=', 'reference')
            ->take(3)
            ->map(fn ($s) => $s['name'].' = '.$s['masked'].' ('.$s['length'].' caracteres'.($s['looks_real'] ? ', parece real' : '').')')
            ->implode("\n");

        return [
            'level' => $label,
            'title' => "{$label} · GitHub: ".Str::limit($finding->location(), 120),
            'location' => $finding->location(),
            'context' => collect([
                $finding->kindLabel(),
                $finding->terms ? implode(', ', array_slice($finding->terms, 0, 3)) : null,
                $own ? 'repositório da sua organização' : 'conta de terceiro ('.$finding->repo_owner.')',
            ])->filter()->implode(' · '),
            'summary' => $finding->triage_reason && $finding->triage_score !== null
                ? $finding->triage_reason
                : (collect($finding->signals ?? [])->pluck('label')->take(2)->implode('; ').'.'),
            'ai' => $finding->ai_score !== null ? "IA {$finding->ai_score}%" : null,
            'action' => self::action($finding),
            'facts' => array_filter([
                'Repositório' => $finding->repo.($finding->repo_fork ? ' (fork)' : ''),
                'Arquivo' => $finding->path,
                'Valores (mascarados)' => $values !== '' ? $values : null,
                'Certeza da classificação' => $finding->triage_score !== null ? $finding->triage_score.'% ('.GithubFinding::confidenceLabel($finding->triage_score).')' : null,
            ]),
            'code' => $finding->fragment ? Str::limit($finding->fragment, 500) : null,
            'links' => [
                'view' => route('alerts.github.show', $finding),
                'github' => $finding->html_url,
                'relevant' => $this->voteUrl($finding, 'relevante'),
                'irrelevant' => $this->voteUrl($finding, 'nao-relevante'),
            ],
            'risk' => $level,
        ];
    }

    /** O que fazer agora, para os casos em que a resposta é sempre a mesma. */
    public static function action(GithubFinding $finding): ?string
    {
        $real = collect($finding->secrets ?? [])->contains('looks_real', true);

        return match (true) {
            $finding->kind === 'private_repo' && $finding->monitor->owns($finding->repo) => 'Torne o repositório privado de novo e troque as chaves que estavam nele: quem clonou ainda tem o código.',
            $finding->kind === 'private_repo' => 'Confira se é uma cópia do seu código; se for, peça a remoção ao GitHub (DMCA) e troque as chaves que estavam nele.',
            $real && $finding->level() === 'critical' => 'Troque a chave agora, antes de pedir a remoção: o histórico do Git guarda o valor mesmo depois de o arquivo sair.',
            default => null,
        };
    }

    public function slack(GithubFinding $finding): array
    {
        $p = $this->parts($finding);
        $esc = fn (string $t) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $t);
        $button = fn (string $label, string $url, ?string $style = null) => array_filter(['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $label], 'url' => $url, 'style' => $style]);

        return [
            'text' => "[{$p['level']}] GitHub: {$p['location']}",
            'blocks' => array_values(array_filter([
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => Str::limit($p['title'], 147), 'emoji' => false]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $esc($p['context'])]]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => ($p['ai'] ? '*'.$p['ai'].'* · ' : '').$esc($p['summary'])]],
                $p['action'] ? ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*O que fazer:* '.$esc($p['action'])]] : null,
                $p['code'] ? ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '```'.$esc(str_replace('```', "'''", $p['code'])).'```']] : null,
                $p['facts'] ? ['type' => 'section', 'fields' => array_values(array_map(fn ($label, $value) => ['type' => 'mrkdwn', 'text' => "*{$label}*\n".$esc(Str::limit($value, 280))], array_keys($p['facts']), $p['facts']))] : null,
                ['type' => 'actions', 'elements' => [
                    $button('Ver no Security Monitor', $p['links']['view'], 'primary'),
                    $button('Abrir no GitHub', $p['links']['github']),
                    $button('Relevante', $p['links']['relevant']),
                    $button('Não é relevante', $p['links']['irrelevant']),
                ]],
            ])),
        ];
    }

    public function discord(GithubFinding $finding): array
    {
        $p = $this->parts($finding);
        $esc = fn (string $t) => preg_replace('/([\\\\*_~`|>\[\]])/', '\\\\$1', $t);
        $link = fn (string $label, string $url) => '['.$label.']('.$url.')';

        $fields = collect($p['facts'])->map(fn ($value, $label) => ['name' => $label, 'value' => Str::limit($esc($value), 1000), 'inline' => $label !== 'Valores (mascarados)'])->values()->push([
            'name' => "\u{200B}",
            'value' => implode(' · ', [
                $link('Ver no Security Monitor', $p['links']['view']),
                $link('Abrir no GitHub', $p['links']['github']),
                $link('Relevante', $p['links']['relevant']),
                $link('Não é relevante', $p['links']['irrelevant']),
            ]),
            'inline' => false,
        ])->all();

        $description = $esc($p['context'])."\n\n".($p['ai'] ? '**'.$p['ai'].'** · ' : '').$esc($p['summary'])
            .($p['action'] ? "\n\n**O que fazer:** ".$esc($p['action']) : '')
            .($p['code'] ? "\n```\n".str_replace('```', "'''", $p['code'])."\n```" : '');

        return [
            'content' => "**{$p['level']}** · Monitoramento do GitHub",
            'embeds' => [array_filter([
                'title' => Str::limit($p['location'], 250),
                'url' => $p['links']['view'],
                'color' => self::COLORS[$p['risk']] ?? self::COLORS['low'],
                'description' => Str::limit($description, 4000),
                'fields' => $fields,
                'timestamp' => now()->toIso8601String(),
            ])],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    public function telegram(GithubFinding $finding): array
    {
        $p = $this->parts($finding);
        $esc = fn (string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $facts = collect($p['facts'])->map(fn ($value, $label) => "<b>{$label}:</b> ".$esc(Str::limit($value, 200)))->implode("\n");

        $text = (self::ICONS[$p['risk']] ?? '⚪').' <b>'.$p['level'].'</b> · Monitoramento do GitHub'
            ."\n\n<b>".$esc(Str::limit($p['location'], 200)).'</b>'
            ."\n".$esc($p['context'])
            ."\n\n".($p['ai'] ? '<b>'.$p['ai'].'</b> · ' : '').$esc(Str::limit($p['summary'], 300))
            .($p['action'] ? "\n\n<b>O que fazer:</b> ".$esc($p['action']) : '')
            .($p['code'] ? "\n\n<pre>".$esc(Str::limit($p['code'], 400)).'</pre>' : '')
            .($facts !== '' ? "\n\n".$facts : '');

        return [
            // Sem cortar: um corte no meio de uma etiqueta quebraria o HTML (o texto fica bem abaixo dos 4.096 do Telegram).
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [
                [['text' => 'Ver no Security Monitor', 'url' => $p['links']['view']], ['text' => 'Abrir no GitHub', 'url' => $p['links']['github']]],
                [['text' => '👍 Relevante', 'url' => $p['links']['relevant']], ['text' => '👎 Não é relevante', 'url' => $p['links']['irrelevant']]],
            ]],
        ];
    }

    private function voteUrl(GithubFinding $finding, string $acao): string
    {
        return URL::temporarySignedRoute('github.feedback.signed', now()->addDays(30), ['finding' => $finding->id, 'acao' => $acao]);
    }
}
