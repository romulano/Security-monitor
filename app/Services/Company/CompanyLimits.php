<?php

namespace App\Services\Company;

use App\Exceptions\ActionRefused;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\GithubMonitor;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Os limites da empresa nos serviços (§4.8, D1; DESENHO-5.4 §4): na empresa o limite é da empresa, não de quem cria
 * (a conta pessoal continua com o "5 por criador"). Conta o que a empresa tem (rascunhos inclusive, para o duplo clique
 * não passar), recusa com a mensagem e manda os avisos de 80% e 100% (CompanyDomainNotices) com o link da tela de cada
 * limite. A conferência é com a empresa travada pelo chamador (lockForUpdate), a mesma trava do apagar setor.
 */
class CompanyLimits
{
    /** A tela de cada limite, para o link do aviso. */
    public const SCREENS = [
        'accounts' => 'company.accounts',
        'alert_profiles' => 'alerts.news',
        'github_monitors' => 'alerts.github',
        'general_channels' => 'alerts.notifiers',
        'sector_channels' => 'alerts.notifiers',
        'webhooks' => 'alerts.notifiers',
    ];

    /** O fim de toda recusa de limite para quem administra a empresa (Designer, rodada 3). */
    public const MORE = 'Precisa de mais? Escreva para contato@example.com.';

    public function __construct(private Companies $companies, private CompanyDomainNotices $notices, private Visibility $visibility) {}

    /** Quanto a empresa usa deste limite (`$sectorId`: o setor dos canais de setor). */
    public function used(Company $company, string $limit, ?int $sectorId = null): int
    {
        $channels = fn () => NotificationChannel::query()->where('company_id', $company->id);

        return match ($limit) {
            'accounts' => $this->companies->accountsUsed($company) + CompanyInvite::query()->where('company_id', $company->id)->pending()->count(),
            'alert_profiles' => AlertProfile::query()->where('company_id', $company->id)->count(),
            'github_monitors' => GithubMonitor::query()->where('company_id', $company->id)->count(),
            'general_channels' => $channels()->whereNull('sector_id')->count(),
            'sector_channels' => $channels()->where('sector_id', $sectorId)->count(),
            'webhooks' => $channels()->where('type', 'webhook')->count(),
        };
    }

    /** Quanto ainda cabe (zero quando chegou ao limite), para as telas esconderem ou avisarem o botão de criar. */
    public function left(Company $company, string $limit, ?int $sectorId = null): int
    {
        return max(0, $this->companies->limit($company, $limit) - $this->used($company, $limit, $sectorId));
    }

    /** Os alertas de Notícias da empresa da conta: quanto cabe ainda. Conta pessoal: nulo (vale o limite de quem cria). */
    public function alertsLeft(User $user): ?int
    {
        $company = $user->companyMembership?->company;

        return $company === null ? null : $this->left($company, 'alert_profiles');
    }

    /** Os alertas de Notícias da empresa da conta: [usados, limite], para o "{n} de {max} alertas da empresa". Conta pessoal: nulo. @return array{int, int}|null */
    public function alertsUsage(User $user): ?array
    {
        $company = $user->companyMembership?->company;

        return $company === null ? null : [$this->used($company, 'alert_profiles'), $this->companies->limit($company, 'alert_profiles')];
    }

    /**
     * A empresa no limite de alertas de Notícias, como a conta lê: quem administra exclui qualquer um e pode pedir mais; quem
     * responde por um setor só exclui os do seu setor (Designer, rodada 3).
     */
    public function alertsFullMessage(?User $user, int $max): string
    {
        return $user === null || $this->visibility->canUseNoSector($user)
            ? "A empresa chegou ao limite de {$max} alertas de Notícias. Para criar outro, exclua um que não use mais. ".self::MORE
            : "A empresa chegou ao limite de {$max} alertas de Notícias (somando todos os setores). Para criar outro, exclua um do seu setor que não use mais ou fale com quem administra a empresa.";
    }

    /** O limite de alertas de Notícias da empresa da conta (o ajustado pelo admin do Security Monitor ou o padrão). */
    public function alertsMax(User $user): int
    {
        return $this->companies->limit($user->companyMembership->company, 'alert_profiles');
    }

    /** Recusa o cadastro novo com a empresa travada no limite de alertas de Notícias. */
    public function assertAlertRoom(Company $locked, ?User $by = null): void
    {
        $max = $this->companies->limit($locked, 'alert_profiles');

        if ($this->used($locked, 'alert_profiles') >= $max) {
            throw new ActionRefused($this->alertsFullMessage($by, $max));
        }
    }

    /** O aviso de 80%/100% depois do commit de quem criou ou apagou (sem commit, nada muda e nada sai). */
    public function notify(Company|int|null $company, string $limit, ?int $sectorId = null): void
    {
        $id = $company instanceof Company ? $company->id : $company;

        if ($id === null) {
            return;
        }

        DB::afterCommit(function () use ($id, $limit, $sectorId) {
            $company = Company::query()->find($id);

            if ($company !== null && ($limit !== 'sector_channels' || $sectorId !== null)) {
                $this->notices->limit($company, $limit, $this->used($company, $limit, $sectorId), $this->companies->limit($company, $limit),
                    sectorId: $sectorId, link: route(self::SCREENS[$limit]));
            }
        });
    }
}
