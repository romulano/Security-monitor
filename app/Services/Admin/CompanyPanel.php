<?php

namespace App\Services\Admin;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Admin → Empresas (§4.11): as empresas, com a admin e o uso contra os limites. Os domínios contam
 * todas as linhas de posse da empresa, pendentes também (como o limite, E3a2); os subdomínios, os ativos do domínio
 * mais cheio (o limite é por domínio, E3b); os canais de setor, os do setor mais cheio (E5d).
 */
class CompanyPanel
{
    /** Quem ocupa o e-mail recusado na criação (sem diferença de caixa), para o admin do Security Monitor decidir. */
    public function occupant(?string $email): ?User
    {
        return $email === null || $email === '' ? null
            : User::query()->with('companyMembership.company')->whereRaw('lower(email) = lower(?)', [trim($email)])->first();
    }

    public function list(): LengthAwarePaginator
    {
        $count = fn (string $table, string $where = '') => "(select count(*) from {$table} t where t.company_id = companies.id{$where})";
        // O mais cheio de cada limite por domínio ou por setor (E5d; Designer, item 7 da E7b): uma subconsulta por coluna, sem N+1.
        $fullest = fn (string $inner) => "(select coalesce(max(c), 0) from ({$inner}) x)";

        return Company::query()->select('companies.*')
            ->withCount(['members as accounts_used' => fn ($query) => $query->whereNull('deactivated_at'), 'ownedDomains as domains_used', 'sectors as sectors_used'])
            // As contas contam também os convites pendentes, como o limite (CompanyLimits::used; Designer, rodada 3).
            ->selectRaw($count('company_invites', ' and t.accepted_at is null and t.revoked_at is null and t.expires_at > now()').' as invites_pending')
            ->selectRaw($count('alert_profiles').' as alerts_used')
            ->selectRaw($count('github_monitors').' as github_used')
            ->selectRaw($count('notification_channels', ' and t.sector_id is null').' as general_channels_used')
            ->selectRaw($count('notification_channels', " and t.type = 'webhook'").' as webhooks_used')
            ->selectRaw($fullest('select count(*) as c from notification_channels t where t.company_id = companies.id and t.sector_id is not null group by t.sector_id').' as sector_channels_used')
            ->selectRaw($fullest("select count(*) as c from owned_subdomains s join owned_domains d on d.id = s.owned_domain_id where d.company_id = companies.id and s.status = 'active' group by s.owned_domain_id").' as subdomains_used')
            ->with(['members' => fn ($query) => $query->where('role', CompanyRole::Admin)->with('user:id,name,email,email_verified_at')])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(40, pageName: 'empresas');
    }
}
