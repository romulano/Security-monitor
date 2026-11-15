<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Webhook\WebhookConnector;
use App\Services\Company\ChannelScope;
use App\Services\Company\Companies;
use App\Services\Company\CompanyChannels;
use App\Services\Company\Viewer;
use App\Services\Company\Visibility;
use Illuminate\Support\Collection;

/**
 * /alertas/notificadores: os apps (Slack, Discord, Telegram, webhook), o que cada
 * app conectado recebe (notícias, Radar de domínios, GitHub) e a pausa do envio,
 * que segura tudo sem desconectar o app. As escolhas valem para o envio na
 * hora, os segurados, o reenvio e o resumo.
 */
class AlertCenter
{
    public function __construct(private Visibility $visibility, private CompanyChannels $channels, private Companies $companies) {}

    public function page(User $user): array
    {
        // Slack, Discord, Telegram e os webhooks (a ordem de NotificationChannel::APPS).
        $channels = $user->notificationChannels
            ->sortBy(fn (NotificationChannel $channel) => array_search($channel->type, array_keys(NotificationChannel::APPS), true))
            ->values();
        // Os de alerta e, com os testes liberados, o aviso deles.
        $types = NotificationChannel::typesFor($user);
        $webhooks = $channels->where('type', 'webhook')->count();

        return [
            // Os apps a oferecer em "Conectar outro app": os que faltam e o webhook, até o limite (a conta pode ter vários).
            'missing' => collect(array_keys(NotificationChannel::APPS))
                ->filter(fn (string $type) => $type === 'webhook' ? $webhooks < WebhookConnector::max() : ! $channels->contains('type', $type))
                ->values(),
            'webhooks' => $webhooks,
            'channels' => $channels,
            'types' => $types,
            // Quem recebe cada tipo agora: conectado, sem pausa e com o tipo ligado.
            'receivers' => collect($types)
                ->map(fn ($label, string $type) => $channels->filter(fn (NotificationChannel $channel) => $channel->receives($type))->values()),
            // O que manda para estes canais: o que a conta criou e ainda vê (até a E5b, o envio vai para quem criou).
            'sources' => [
                'news' => AlertProfile::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->whereNotNull('completed_at')->exists(),
                'radar' => LookalikeWatch::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->exists(),
                'github' => GithubMonitor::query()->visibleTo($user)->where('user_id', $user->id)->where('status', 'active')->exists(),
                NotificationChannel::TESTS => $user->pentestDomains()->exists(),
            ],
        ];
    }

    /**
     * A conta de empresa (E5a2, DESENHO-5.4 §2.4): um bloco para os canais gerais e um por setor que ela vê (a admin, todos),
     * cada um com os canais, o "N de 5" e se a conta configura ali (gerais: só a admin; setor: a admin e quem responde
     * por ele, P2). Quem só vê enxerga onde chega cada canal, sem botões.
     */
    public function companyPage(User $user): array
    {
        $viewer = $this->visibility->viewer($user);
        $company = Company::query()->findOrFail($viewer->companyId);
        $order = array_keys(NotificationChannel::APPS);
        $channels = NotificationChannel::query()->visibleTo($user)->orderBy('id')->get()
            ->sortBy(fn (NotificationChannel $channel) => array_search($channel->type, $order, true))->values();
        $sectors = Sector::query()->where('company_id', $company->id)
            ->when($viewer->kind !== Viewer::ADMIN, fn ($query) => $query->whereIn('id', $user->sectors()->select('sectors.id')))
            ->orderBy('name')->get();
        $webhooks = NotificationChannel::query()->where('company_id', $company->id)->where('type', 'webhook')->count();
        $webhookLimit = $this->companies->limit($company, 'webhooks');

        $block = function (ChannelScope $scope, string $key, string $title, string $about, string $who) use ($user, $company, $channels, $webhooks, $webhookLimit) {
            $mine = $channels->filter(fn (NotificationChannel $channel) => ChannelScope::of($channel) == $scope)->values();
            $limit = $this->companies->limit($company, (string) $scope->limitName());
            $manages = $this->channels->manages($user, $scope);

            return [
                'key' => $key, 'title' => $title, 'about' => $about, 'channels' => $mine, 'used' => $mine->count(), 'limit' => $limit,
                'manages' => $manages, 'who' => $who,
                // Conectar: os apps (vários do mesmo no mesmo lugar, P4) e o webhook, enquanto cabe na empresa.
                'connect' => $manages && $mine->count() < $limit
                    ? array_values(array_filter(array_keys(NotificationChannel::APPS), fn (string $type) => $type !== 'webhook' || $webhooks < $webhookLimit))
                    : [],
            ];
        };

        return [
            'company' => $company,
            'blocks' => collect([$block(ChannelScope::general($company->id), 'geral', 'Canais gerais',
                'Recebem os avisos do que é da empresa toda (alertas, Radares e monitoramentos sem setor).', 'Só quem administra a empresa muda estes canais.')])
                ->merge($sectors->map(fn (Sector $sector) => $block(ChannelScope::sector($company->id, $sector->id), (string) $sector->id, 'Canais do setor '.$sector->name,
                    "Recebem só os avisos do que é do setor {$sector->name}. Os canais gerais não recebem estes.",
                    "Só quem administra a empresa ou quem responde pelo setor {$sector->name} muda estes canais."))),
            'managed' => NotificationChannel::query()->manageableBy($user)->pluck('id')->all(),
            'types' => NotificationChannel::typesFor($user),
            'webhooks' => $webhooks,
            'webhookLimit' => $webhookLimit,
        ];
    }

    /**
     * Grava os tipos ligados de cada app. Só mexe nos canais que a conta configura (os dela; na empresa, os do escopo
     * que ela configura, Visibility::manages); um canal fora do formulário fica como estava, e um tipo que a página
     * não mostrou (o aviso dos testes, sem a liberação) também.
     *
     * @param  array<int|string, array<string, bool>>  $choices  canal → tipo → ligado
     */
    public function save(User $user, array $choices): Collection
    {
        $changed = collect();
        $types = array_keys(NotificationChannel::typesFor($user));

        foreach (NotificationChannel::query()->manageableBy($user)->orderBy('id')->get() as $channel) {
            if (! isset($choices[$channel->id])) {
                continue;
            }

            $muted = [
                ...collect($types)->reject(fn (string $type) => (bool) ($choices[$channel->id][$type] ?? false))->values()->all(),
                ...array_values(array_diff($channel->muted_types ?? [], $types)),
            ];

            if ($muted !== array_values($channel->muted_types ?? [])) {
                $channel->forceFill(['muted_types' => $muted])->save();
                $changed->push($channel);
            }
        }

        return $changed;
    }

    /** Pausa ou retoma o envio para este app; devolve se ficou pausado. */
    public function togglePause(NotificationChannel $channel): bool
    {
        $paused = ! $channel->isPaused();
        $channel->forceFill(['paused_at' => $paused ? now() : null])->save();

        return $paused;
    }
}
