<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Github\GithubInterview;
use App\Services\Keys\KeysPanel;
use App\Services\Lookalike\RadarInterview;
use App\Services\Pentest\PentestPanel;

/**
 * /alertas/integracao: o que o Security Monitor vigia para o cliente e a porta de
 * entrada de cada fonte (Notícias, Radar de domínios, GitHub). Cada uma tem a
 * sua entrevista, e integrar uma não integra a outra; a integrada mostra os
 * números dela e abre a página dela. Com PENTEST_ALLOWED, os testes de segurança
 * entram como mais uma frente, com o cartão deles (sem a liberação do admin, o
 * cartão diz "não liberado").
 */
class IntegrationsPanel
{
    public function __construct(
        private RadarInterview $radar,
        private AlertInbox $inbox,
        private PentestPanel $tests,
        private AlertPanel $alerts,
        private GithubInterview $github,
        private KeysPanel $keys,
    ) {}

    public function page(User $user): array
    {
        $profiles = AlertProfile::query()->visibleTo($user)->oldest('id')->get();
        $watches = LookalikeWatch::query()->visibleTo($user)->with('domain')->oldest('id')->get();
        $ready = $watches->filter->isCompleted();

        return [
            'profiles' => $profiles,
            'completed' => $profiles->whereNotNull('completed_at'),
            // O limite é por quem cria (até a E5d): a lista mostra também o que outras contas da empresa criaram.
            'canAddProfile' => $this->alerts->canCreate($user),
            // O rascunho que a tela oferece continuar: só se a conta o configura (o membro vê, mas não entra na entrevista).
            'draftManaged' => ($draft = $profiles->whereNull('completed_at')->first()) !== null
                && AlertProfile::query()->manageableBy($user)->whereKey($draft->id)->exists(),
            'watches' => $watches,
            // Quem pode criar: a pessoal, a admin, quem responde por um setor (E2d1).
            'canAddWatch' => $this->radar->mayCreate($user),
            // O rascunho que a conta continua: só o de um Radar que ela configura (o membro não entra na entrevista).
            'radarDraft' => LookalikeWatch::query()->manageableBy($user)->whereNull('completed_at')->oldest('id')->first(),
            // Domínio comprovado em Notícias que ainda não tem Radar: o passo 1 do Radar oferece reaproveitar.
            'proven' => $this->radar->provenDomains($user),
            // Todos os monitoramentos do GitHub que a conta vê (na empresa, vários, P6); o rascunho para continuar e a
            // entrevista para editar, só os que ela configura; criar outro, quem pode e enquanto cabe no limite.
            'monitors' => $monitors = GithubMonitor::query()->visibleTo($user)->oldest('id')->get(),
            'githubDraft' => GithubMonitor::query()->manageableBy($user)->whereNull('completed_at')->oldest('id')->first(),
            'githubEditable' => GithubMonitor::query()->manageableBy($user)->whereNotNull('completed_at')->oldest('id')->first(),
            'canAddMonitor' => $this->github->mayCreate($user),
            // Alertas dos últimos 30 dias e pendentes de avaliação, por fonte integrada; e as ameaças abertas do Radar.
            'summary' => $this->inbox->summary($user),
            'threats' => $this->inbox->threats($ready),
            'lastRadarScan' => $ready->max('last_scan_at'),
            'tests' => $this->tests->card($user, now()),
            // Chaves expostas: para toda conta (só roda em domínio comprovado).
            'keys' => $this->keys->card($user),
            'channels' => $user->notificationChannels()->get()
                ->sortBy(fn (NotificationChannel $channel) => array_search($channel->type, array_keys(NotificationChannel::APPS), true))
                ->values(),
        ];
    }
}
