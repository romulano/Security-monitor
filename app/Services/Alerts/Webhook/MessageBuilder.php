<?php

namespace App\Services\Alerts\Webhook;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Pentest\PentestReadyNotice;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * As notificações do webhook em JSON, com o conteúdo das mensagens do Slack e do
 * Discord em campos (e sem os atalhos que elas também não têm: "Ajustar alertas",
 * "Ver todos", rodapé de fonte). O formato é versionado (`version`) e estável:
 * campo novo pode aparecer; mudar ou tirar um campo é uma versão nova.
 *
 *     {"version": 1, "id": "<uuid da entrega>", "event": "alert.match",
 *      "sent_at": "2026-11-01T12:00:00Z", "data": {...}}
 *
 * Eventos: alert.match (notícia que casou com um alerta), alert.grouped (as que
 * chegaram juntas: horário silencioso ou limite por hora), alert.digest (resumo
 * diário), radar.alert (Radar de domínios), github.finding (monitoramento do
 * GitHub), tests.ready (os testes de segurança ligaram e a autorização está
 * pronta) e test. O `sent_at` é preenchido em cada tentativa (WebhookClient::stamp);
 * o `id` fica igual em todas, para o receptor deduplicar.
 */
class MessageBuilder extends AlertMessage
{
    public const VERSION = 1;

    public const EVENTS = [
        'alert.match' => 'notícia que casou com um alerta seu',
        'alert.grouped' => 'notícias que chegaram juntas (horário silencioso ou limite por hora)',
        'alert.digest' => 'o resumo diário',
        'radar.alert' => 'domínio parecido com o seu (Radar de domínios)',
        'github.finding' => 'achado do monitoramento do GitHub',
        'tests.ready' => 'os testes de segurança foram ativados e a sua autorização está pronta',
        'test' => 'o teste do botão “Enviar teste”',
    ];

    /** Os itens de uma lista (agrupados, resumo) param aqui; `count` diz o total. */
    private const LIST_LIMIT = 50;

    /** O envelope de um evento, com um `id` novo: o histórico guarda assim, sem o `sent_at`. */
    public static function envelope(string $event, array $data): array
    {
        return ['version' => self::VERSION, 'id' => (string) Str::uuid(), 'event' => $event, 'data' => $data];
    }

    public function alert(AlertMatch $match): array
    {
        $case = $match->case;
        $profile = $match->profile;
        $pt = $profile->language !== 'en';
        $update = $match->kind === 'case_update';
        $body = $this->body($match, $pt);

        return self::envelope('alert.match', [
            'title' => ($update ? 'Atualização · ' : '').$this->title($case, $pt),
            'kind' => $update ? 'case_update' : 'new_case',
            'criticity' => $match->criticity,
            'language' => $pt ? 'pt' : 'en',
            'summary' => $body ? $this->plain($body, 2000) : null,
            'case' => $this->caseData($case, $pt, full: true),
            'match' => [
                'alerts' => $this->alertList($match),
                'cites_company' => $match->hasBrandReason(),
                'terms' => $this->terms($match),
                'reasons' => $match->reasonLines(),
                'score' => (int) $match->score,
                'min_criticity' => $profile->min_criticity,
            ],
            'ai_review' => $this->aiReview($match),
            'links' => [
                'case' => route('feed.show', $case->uuid),
                'relevant' => URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDays(30), ['match' => $match->id]),
                'irrelevant' => URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id]),
            ],
        ]);
    }

    /** @param  Collection<int, AlertMatch>  $matches */
    public function grouped(Collection $matches, string $reason): array
    {
        $n = $matches->count();

        return self::envelope('alert.grouped', [
            'title' => "{$n} alertas novos",
            'reason' => $reason === 'quiet_hours' ? 'quiet_hours' : 'throttled',
            'count' => $n,
            'alerts' => $matches->take(self::LIST_LIMIT)->map(fn (AlertMatch $m) => [
                'kind' => $m->kind === 'case_update' ? 'case_update' : 'new_case',
                'criticity' => $m->criticity,
                'alert' => $m->profile->displayName(),
                'terms' => $this->terms($m),
                'case' => $this->caseData($m->case, $m->profile->language !== 'en'),
            ])->values()->all(),
        ]);
    }

    public function digest(array $data): array
    {
        $pt = $data['lang'] !== 'en';
        $news = (bool) ($data['news'] ?? true);
        $item = fn (array $r) => ['level' => $r['level'], 'label' => $r['label'], 'name' => $r['host'], 'url' => $r['url'], 'note' => $r['note'] ?: null];

        return self::envelope('alert.digest', [
            'title' => 'Resumo do Security Monitor · '.$data['date']->format('d/m/Y'),
            'date' => $data['date']->toDateString(),
            'period' => ['start' => self::time($data['start']), 'end' => self::time($data['end'])],
            'language' => $pt ? 'pt' : 'en',
            'news' => $news ? [
                'total' => (int) $data['total'],
                'counts' => collect(AlertProfile::LEVELS)->mapWithKeys(fn ($l) => [$l => (int) ($data['counts'][$l] ?? 0)])->all(),
                'matched' => collect($data['mine'])->map(fn (array $m) => [
                    'criticity' => $m['criticity'],
                    'why' => $m['why'],
                    'already_sent' => $m['sent_at'] !== null,
                    'case' => $this->caseData($m['case'], $pt),
                ])->values()->all(),
                'overview' => $data['scope'] === 'all' ? collect($data['panorama'])->map(fn (NewsCase $c) => $this->caseData($c, $pt))->values()->all() : [],
                'overview_total' => $data['scope'] === 'all' ? (int) $data['panorama_total'] : 0,
            ] : null,
            'radar' => array_map($item, $data['radar'] ?? []),
            'github' => array_map($item, $data['github'] ?? []),
            'schedule' => ['time' => $data['time'], 'weekdays' => $data['weekdays'] ?? [], 'timezone' => $data['timezone'] ?? null],
        ]);
    }

    public function test(NotificationChannel $channel): array
    {
        return self::envelope('test', [
            'title' => 'Teste do Security Monitor',
            'text' => 'Este webhook está conectado e vai receber os alertas do Security Monitor. Recebe: '.$channel->typesLabel().($channel->isPaused() ? ' (envio pausado)' : '').'.',
            'webhook' => [
                'name' => $channel->name,
                'types' => array_values(array_filter(array_keys(NotificationChannel::TYPES), fn (string $type) => $channel->wants($type))),
                'paused' => $channel->isPaused(),
                'realtime' => (bool) $channel->realtime,
                'digest' => $channel->digest ? ['time' => $channel->digest_time, 'weekdays' => array_map('intval', $channel->digest_days ?? []), 'timezone' => $channel->timezone] : null,
                'signed' => $channel->signs(),
            ],
        ]);
    }

    /**
     * O aviso de que os testes de segurança ligaram (PentestReadyNotice): o texto do Blue
     * Team, os domínios e o link da área, nunca dado do teste.
     *
     * @param  list<string>  $domains
     */
    public function testsReady(array $domains, string $url): array
    {
        return self::envelope('tests.ready', [
            'title' => 'Testes de segurança ativados',
            'text' => PentestReadyNotice::text($domains),
            'domains' => array_values($domains),
            'url' => $url,
        ]);
    }

    /** A matéria em campos; `full` acrescenta os exploits públicos (uma consulta a mais por matéria). */
    private function caseData(NewsCase $case, bool $pt, bool $full = false): array
    {
        return [
            'id' => $case->uuid,
            'title' => $this->title($case, $pt),
            'criticity' => $case->criticity,
            'url' => route('feed.show', $case->uuid),
            'cves' => array_values(array_unique([...array_values((array) ($case->cves ?? [])), ...$case->indicatorValues('cves')])),
            'threat_actors' => array_values((array) ($case->threat_actors ?? [])),
            'sectors' => array_values((array) ($case->victim_sectors ?? [])),
            'products' => $case->indicatorValues('affected_products'),
            'sources' => max(1, (int) $case->source_count),
            'first_seen_at' => self::time($case->first_at),
        ] + ($full ? ['exploits' => $case->exploits()->showcase()->pluck('exploits.id')->map(fn ($id) => 'EDB-'.$id)->values()->all()] : []);
    }

    /** "Fortinet", "FortiOS": os termos que casaram, sem repetir. */
    private function terms(AlertMatch $match): array
    {
        return collect($match->reasons ?? [])->pluck('label')->filter()->unique()->values()->all();
    }

    /** O parecer do juiz de IA, quando o alerta passou por ele (a nota é nula quando a IA não respondeu). */
    private function aiReview(AlertMatch $match): ?array
    {
        return $match->ai_reviewed_at ? ['relevance' => $match->ai_relevance !== null ? (int) $match->ai_relevance : null, 'reason' => $match->ai_reason] : null;
    }

    /** Data e hora em UTC, no ISO 8601 ("2026-11-01T12:00:00Z"). */
    private static function time(?DateTimeInterface $at): ?string
    {
        return $at ? Carbon::instance($at)->utc()->toIso8601ZuluString() : null;
    }
}
