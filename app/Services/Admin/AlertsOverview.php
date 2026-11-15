<?php

namespace App\Services\Admin;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Alerts\Discord\DiscordClient;
use App\Services\Alerts\Telegram\TelegramClient;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Admin → Alertas → Visão geral: saúde, números, pendências, configuração e os botões de operação. */
class AlertsOverview
{
    public function page(): array
    {
        $since24h = now()->subDay();
        $since30d = now()->subDays(30);
        $relevant30d = AlertMatch::query()->relevant()->where('matched_at', '>=', $since30d);

        $digestChannels = NotificationChannel::query()->where('status', 'active')->whereNull('paused_at')->where('digest', true)
            ->whereHas('user.alertProfile', fn ($q) => $q->where('status', 'active'))->count();

        $staleDrafts = AlertProfile::query()->whereNull('completed_at')->where('updated_at', '<', now()->subDays(7))->with('user')->get();

        return [
            'health' => [
                'realtime' => Settings::bool('alerts.enabled') && Settings::bool('alerts.realtime_enabled'),
                'digest' => Settings::bool('alerts.enabled') && Settings::bool('alerts.digest_enabled'),
                'discovery' => Settings::bool('alerts.enabled') && Settings::bool('alerts.discovery_enabled'),
                'oauth' => filled(config('secmon.alerts.slack.client_id')) && filled(config('secmon.alerts.slack.client_secret')),
                'discord' => DiscordClient::configured(),
                'telegram' => TelegramClient::configured(),
            ],
            'stats' => [
                'clients' => User::query()->where('is_admin', false)->count(),
                'active' => AlertProfile::query()->where('status', 'active')->count(),
                'paused' => AlertProfile::query()->where('status', 'paused')->count(),
                'drafts' => AlertProfile::query()->whereNull('completed_at')->count(),
                'stale_drafts' => $staleDrafts->count(),
                'channels' => NotificationChannel::query()->where('status', 'active')->count(),
                'channels_oauth' => NotificationChannel::query()->where('status', 'active')->where('method', 'oauth')->count(),
                'channels_discord' => NotificationChannel::query()->where('status', 'active')->where('type', 'discord')->count(),
                'channels_telegram' => NotificationChannel::query()->where('status', 'active')->where('type', 'telegram')->count(),
                'channels_webhook' => NotificationChannel::query()->where('status', 'active')->where('type', 'webhook')->count(),
                'broken' => NotificationChannel::query()->where('status', 'broken')->count(),
                'sent_24h' => NotificationDelivery::query()->whereIn('kind', ['realtime', 'grouped'])->where('status', 'sent')->where('created_at', '>=', $since24h)->count(),
                'digests_today' => NotificationDelivery::query()->where('kind', 'digest')->whereDate('digest_date', now()->timezone(Settings::get('alerts.default_timezone'))->toDateString())->count(),
                'digest_channels' => $digestChannels,
                'failed_24h' => NotificationDelivery::query()->where('status', 'failed')->where('created_at', '>=', $since24h)->count(),
                'irrelevant_rate' => ($total = (clone $relevant30d)->count())
                    ? round((clone $relevant30d)->where('feedback', 'irrelevant')->count() / $total * 100)
                    : 0,
                'ai_held_30d' => AlertMatch::query()->where('suppressed_reason', 'ai_low_relevance')->where('matched_at', '>=', $since30d)->count(),
            ],
            'issues' => [
                'broken' => NotificationChannel::query()->where('status', 'broken')->with('user.alertProfile')->latest('broken_at')->limit(5)->get(),
                'domains' => MonitoredDomain::query()->ofAlerts()->whereIn('discovery_status', ['blocked', 'failed'])->with('profile.user')->limit(5)->get(),
                'drafts' => $staleDrafts->take(5),
            ],
            'queues' => DB::table('jobs')->whereIn('queue', ['alerts', 'judge', 'notifications', 'discovery'])->selectRaw('queue, count(*) as n')->groupBy('queue')->pluck('n', 'queue'),
            'topTechs' => $this->topTechs($since30d),
            'config' => [
                'enabled' => Settings::bool('alerts.enabled'),
                'realtime_enabled' => Settings::bool('alerts.realtime_enabled'),
                'digest_enabled' => Settings::bool('alerts.digest_enabled'),
                'discovery_enabled' => Settings::bool('alerts.discovery_enabled'),
                'digest_default_time' => (string) Settings::get('alerts.digest_default_time'),
                'default_timezone' => (string) Settings::get('alerts.default_timezone'),
                'max_per_hour' => Settings::int('alerts.max_per_hour'),
                'min_score' => Settings::int('alerts.min_score'),
                'ai_review_enabled' => Settings::bool('alerts.ai_review_enabled'),
                'ai_review_threshold' => Settings::int('alerts.ai_review_threshold'),
                'github_ai_threshold' => Settings::int('github.ai_threshold'),
                'discovery_every_days' => Settings::int('alerts.discovery_every_days'),
                'consent_version' => (string) Settings::get('alerts.consent_version'),
            ],
        ];
    }

    /**
     * @param  array{enabled: bool, realtime_enabled: bool, digest_enabled: bool, discovery_enabled: bool, digest_default_time: string, default_timezone: string, max_per_hour: int|string, min_score: int|string, ai_review_enabled: bool, ai_review_threshold: int|string, github_ai_threshold: int|string, discovery_every_days: int|string, consent_version: string}  $config
     */
    public function saveConfig(array $config): void
    {
        Settings::setMany([
            'alerts.enabled' => $config['enabled'],
            'alerts.realtime_enabled' => $config['realtime_enabled'],
            'alerts.digest_enabled' => $config['digest_enabled'],
            'alerts.discovery_enabled' => $config['discovery_enabled'],
            'alerts.digest_default_time' => $config['digest_default_time'],
            'alerts.default_timezone' => $config['default_timezone'],
            'alerts.max_per_hour' => (int) $config['max_per_hour'],
            'alerts.min_score' => (int) $config['min_score'],
            'alerts.ai_review_enabled' => $config['ai_review_enabled'],
            'alerts.ai_review_threshold' => (int) $config['ai_review_threshold'],
            'github.ai_threshold' => (int) $config['github_ai_threshold'],
            'alerts.discovery_every_days' => (int) $config['discovery_every_days'],
            'alerts.consent_version' => $config['consent_version'],
        ]);
    }

    /** Manda já o resumo de hoje a quem ainda não recebeu; devolve o que o comando contou. */
    public function sendDigests(): string
    {
        Artisan::call('secmon:alerts-digest', ['--force' => true]);

        return trim(Artisan::output());
    }

    /** Recompila todos os perfis e regrava o percolator; devolve o que o comando contou. */
    public function recompileProfiles(): string
    {
        Artisan::call('secmon:alerts-recompile');

        return trim(Artisan::output());
    }

    /** Termos que mais geram alerta e quanto deles foi marcado como não relevante. */
    private function topTechs(Carbon $since): array
    {
        $rows = [];

        AlertMatch::query()->relevant()->where('matched_at', '>=', $since)
            ->select(['id', 'reasons', 'feedback'])
            ->limit(5000)
            ->get()
            ->each(function (AlertMatch $match) use (&$rows) {
                foreach (collect($match->reasons)->whereIn('type', ['stack', 'mention'])->pluck('label')->unique() as $label) {
                    $rows[$label]['alerts'] = ($rows[$label]['alerts'] ?? 0) + 1;
                    $rows[$label]['irrelevant'] = ($rows[$label]['irrelevant'] ?? 0) + ($match->feedback === 'irrelevant' ? 1 : 0);
                }
            });

        $profiles = DB::table('alert_profile_techs')->join('tech_catalog', 'tech_catalog.id', '=', 'alert_profile_techs.tech_id')
            ->selectRaw('tech_catalog.name, tech_catalog.id, count(*) as n')->groupBy('tech_catalog.name', 'tech_catalog.id')->get()->keyBy('name');

        uasort($rows, fn ($a, $b) => $b['alerts'] <=> $a['alerts']);

        return collect(array_slice($rows, 0, 8, true))->map(fn ($r, $label) => $r + [
            'label' => $label,
            'profiles' => $profiles[$label]->n ?? null,
            'tech_id' => $profiles[$label]->id ?? null,
            'rate' => $r['alerts'] ? round($r['irrelevant'] / $r['alerts'] * 100) : 0,
        ])->values()->all();
    }
}
