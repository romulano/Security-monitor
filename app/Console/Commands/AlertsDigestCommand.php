<?php

namespace App\Console\Commands;

use App\Models\AlertProfile;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Access\Destinations;
use App\Services\Alerts\Notifier;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Roda a cada 5 minutos. Para cada canal com resumo ligado e sem pausa na
 * Central, vê se já passou do horário dele (no fuso dele) e se o resumo do
 * dia ainda não saiu. Se o worker esteve fora do ar, ainda manda até
 * `digest_grace_hours` depois; passou disso, pula o dia em vez de mandar
 * resumo velho. Canal sem notícias, Radar ou GitHub ligados não recebe resumo.
 */
class AlertsDigestCommand extends Command
{
    protected $signature = 'secmon:alerts-digest
        {--channel= : só este canal}
        {--force : manda o resumo de hoje agora, fora do horário (ainda um por dia)}';

    protected $description = 'Envia os resumos diários que estão no horário';

    public function handle(Notifier $notifier): int
    {
        if (! Settings::bool('alerts.enabled') || ! Settings::bool('alerts.digest_enabled')) {
            $this->line('Resumo diário desligado no admin.');

            return self::SUCCESS;
        }

        $sent = 0;
        $grace = (int) config('secmon.alerts.digest_grace_hours', 4);

        $channels = NotificationChannel::query()
            ->where('status', 'active')
            ->whereNull('paused_at')
            ->where('digest', true)
            ->when($this->option('channel'), fn ($q, $id) => $q->whereKey($id))
            ->get();
        $sources = app(Destinations::class);

        foreach ($channels as $channel) {
            // Basta uma fonte ativa que mande para este canal (as do dono, no pessoal; as do escopo, no da empresa):
            // um alerta de notícias (o resumo junta todos), um Radar vigiando ou o monitoramento do GitHub.
            $alerts = $sources->sourcesFor($channel, AlertProfile::query())->get()->contains(fn ($profile) => $profile->isActive());
            $radar = $channel->wants('radar') && $sources->sourcesFor($channel, LookalikeWatch::query())->where('status', 'active')->exists();
            $github = $channel->wants('github') && $sources->sourcesFor($channel, GithubMonitor::query())->where('status', 'active')->exists();

            if (! $alerts && ! $radar && ! $github) {
                continue;
            }

            // Notícias desligadas na Central: o resumo só tem sentido com o Radar vigiando algum domínio ou com o GitHub.
            if ($alerts && ! $channel->wants('news') && ! $radar && ! $github) {
                continue;
            }

            $local = $channel->localNow();
            $scheduled = Carbon::parse($local->format('Y-m-d').' '.$channel->digest_time, $channel->timezone);

            if (! $this->option('force')) {
                $due = in_array($local->dayOfWeekIso, array_map('intval', $channel->digest_days ?? []), true)
                    && $local->greaterThanOrEqualTo($scheduled)
                    && $local->lessThan($scheduled->copy()->addHours($grace));

                if (! $due) {
                    continue;
                }
            }

            $already = NotificationDelivery::query()
                ->where('channel_id', $channel->id)
                ->where('kind', 'digest')
                ->whereDate('digest_date', $local->toDateString())
                ->exists();

            if (! $already && $notifier->digest($channel, $local->copy()->startOfDay())) {
                $sent++;
            }
        }

        $this->info("{$sent} resumo(s) enfileirado(s).");

        return self::SUCCESS;
    }
}
