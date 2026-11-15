<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Mail\CompanyDomainNotice;
use App\Mail\CompanyLimitNotice;
use App\Models\Company;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Mail\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Os e-mails de domínio e de limite da empresa (E3d, DESENHO-5.3 §4, §4.14 do plano; os outros limites na E5d, pelo
 * CompanyLimits), todos pelo Outbox e só para quem configura:
 *
 * - o TXT sumiu (P6: no 1º dia sem ele, "a posse cai em 7 dias") e a posse caiu: para a admin e quem responde pelo setor
 *   do domínio (o domínio sem setor, só a admin), porque o texto leva o TXT com o token da empresa;
 * - o limite em 80% e em 100%: dos domínios da empresa, para a admin; dos subdomínios de um domínio (E3b, pelo mesmo
 *   `limit()` com `$scope`), para a admin e quem responde pelo setor dele. Um aviso por marco (`company_limit_notices`):
 *   ao voltar abaixo do marco, ele pode sair de novo.
 *
 * Empresa suspensa ou desligada não recebe nada (D7: os envios param). Conta pessoal nunca passa por aqui.
 */
class CompanyDomainNotices
{
    /** Os marcos de aviso, em % do limite. */
    public const LEVELS = [80, 100];

    public function __construct(private Outbox $outbox, private Companies $companies) {}

    /** 1º dia sem o TXT de um domínio comprovado da empresa (P6). */
    public function unseen(OwnedDomain $owned): void
    {
        $this->domainMail($owned, 'unseen');
    }

    /** A posse de um domínio da empresa caiu (7 dias sem o TXT). */
    public function lost(OwnedDomain $owned): void
    {
        $this->domainMail($owned, 'lost');
    }

    /** O limite de domínios da empresa depois de cadastrar ou remover um (DomainOwnership; a tela de domínios, E3a3). */
    public function domains(int $companyId): void
    {
        $company = Company::query()->find($companyId);

        if ($company !== null) {
            $used = OwnedDomain::query()->where('company_id', $companyId)->count();
            $this->limit($company, 'domains', $used, $this->companies->limit($company, 'domains'));
        }
    }

    /**
     * Um limite da empresa contra os marcos de 80% e 100%. `$scope` é o domínio dos subdomínios (o limite `subdomains` é
     * por domínio, E3b: chame com o domínio depois de ativar ou tirar um subdomínio); `$sectorId`, o setor dos canais de
     * setor (E5d, CompanyLimits); nulos, a empresa toda. `$link`: a tela do limite (sem ele, a de domínios).
     */
    public function limit(Company $company, string $limit, int $used, int $max, ?OwnedDomain $scope = null, ?int $sectorId = null, ?string $link = null): void
    {
        // Suspensa ou desligada (D7): nada sai, e o marco não é gasto; o próximo cadastro depois de reativar avisa.
        if ($max <= 0 || ! $this->companies->isActive($company)) {
            return;
        }

        $scopeId = $scope?->id ?? $sectorId ?? 0;
        $reached = array_values(array_filter(self::LEVELS, fn (int $level) => $used >= (int) ceil($max * $level / 100)));
        $marks = DB::table('company_limit_notices')->where(['company_id' => $company->id, 'limit' => $limit, 'scope_id' => $scopeId]);

        // Abaixo do marco de novo: o aviso dele pode sair outra vez.
        (clone $marks)->whereNotIn('level', $reached === [] ? [0] : $reached)->delete();

        $new = array_values(array_filter($reached, fn (int $level) => DB::table('company_limit_notices')->insertOrIgnore([
            'company_id' => $company->id, 'limit' => $limit, 'scope_id' => $scopeId, 'level' => $level, 'sent_at' => now(),
        ]) === 1));

        if ($new === []) {
            return;
        }

        // Os dois marcos de uma vez (um lote): só o de 100% vai.
        $level = max($new);
        $what = match ($limit) {
            'domains' => 'domínios',
            'subdomains' => 'subdomínios'.($scope ? ' de '.$scope->domain : ''),
            'alert_profiles' => 'alertas de Notícias',
            'accounts' => 'contas',
            'sector_channels' => 'canais do setor '.DB::table('sectors')->where('id', $sectorId)->value('name'),
            default => Company::LIMIT_LABELS[$limit] ?? $limit,
        };

        // Quem administra e quem responde pelo setor recebem textos diferentes (o que fazer, a quem pedir mais; rodada 3).
        $sector = ($id = $scope?->sector_id ?? $sectorId) !== null ? (string) DB::table('sectors')->where('id', $id)->value('name') : null;
        $admins = DB::table('company_user')->where('company_id', $company->id)->where('role', CompanyRole::Admin->value)->pluck('user_id')->all();

        foreach ($this->recipients($company, $id) as $user) {
            $this->outbox->send(new CompanyLimitNotice($user->name, $company->name, $what, $used, $max, $level, $link ?? url('/empresa/dominios'), $limit,
                in_array($user->id, $admins, true) ? null : $sector), $user->email, 'empresa-limite', $user);
        }
    }

    private function domainMail(OwnedDomain $owned, string $kind): void
    {
        $company = $owned->company;

        if ($company === null || ! $this->companies->isActive($company)) {
            return;
        }

        $txt = DomainOwnership::PREFIX.$company->domain_token;
        $stopped = $kind === 'lost' ? $this->stopped($owned) : [];

        foreach ($this->recipients($company, $owned->sector_id) as $user) {
            $this->outbox->send(
                new CompanyDomainNotice($kind, $user->name, $company->name, $owned->domain, $txt, $owned->unseen_since, $owned->fallsAt(), $stopped, url('/empresa/dominios')),
                $user->email, 'empresa-dominio', $user,
            );
        }
    }

    /** O que parou com a queda: as cópias da empresa com o nome (ou um subdomínio dele), por tipo. @return list<string> */
    private function stopped(OwnedDomain $owned): array
    {
        $copies = DomainOwner::anySectorOf($owned->company_id, $owned->user_id)->copies(MonitoredDomain::query())
            ->where(fn ($q) => $q->where('domain', $owned->domain)->orWhere('domain', 'like', '%.'.$owned->domain))
            ->whereDoesntHave('pentestDomain')
            ->with(['profile', 'lookalikeWatch'])
            ->get()
            // Só o que parou agora (a cópia comprovada por outra linha, a dela, não parou) e é do setor do domínio: o
            // número de itens de outro setor não vai a quem responde por este (F1 do Red Team da E3d). O domínio sem
            // setor serve a todos; o webhook é da conta e não tem setor.
            ->reject(fn (MonitoredDomain $row) => $row->isVerified())
            ->filter(fn (MonitoredDomain $row) => $owned->sector_id === null
                || ($root = $row->profile ?? $row->lookalikeWatch) === null
                || $root->sector_id === $owned->sector_id);
        $plural = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
        $alerts = $copies->whereNotNull('profile_id')->count();
        $radars = $copies->whereNull('profile_id')->filter(fn (MonitoredDomain $row) => $row->lookalikeWatch !== null)->count();
        $hooks = $copies->whereNull('profile_id')->count() - $radars;

        return array_values(array_filter([
            $radars ? $plural($radars, 'Radar', 'Radares') : null,
            $alerts ? $plural($alerts, 'alerta de Notícias (a descoberta)', 'alertas de Notícias (a descoberta)') : null,
            $hooks ? $plural($hooks, 'webhook', 'webhooks') : null,
        ]));
    }

    /**
     * Quem configura: a admin (ativa) e, se o domínio (ou o canal) tem setor, quem responde por ele (a marca `is_manager`,
     * o papel e a conta ativa, como o Visibility). @return Collection<int, User>
     */
    private function recipients(Company $company, ?int $sector): Collection
    {

        return User::query()
            ->whereIn('id', DB::table('company_user')->where('company_id', $company->id)->whereNull('deactivated_at')
                ->where(fn ($q) => $q->where('role', CompanyRole::Admin->value)
                    ->when($sector !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('role', CompanyRole::SectorManager->value)
                        ->whereIn('user_id', DB::table('sector_user')->where('sector_id', $sector)->where('is_manager', true)->select('user_id')))))
                ->select('user_id'))
            ->orderBy('id')
            ->get();
    }
}
