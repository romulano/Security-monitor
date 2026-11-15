<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/** A página "Contas da empresa" de quem administra: as contas, os convites abertos e o uso contra o limite. */
class CompanyAccounts
{
    public function __construct(private Companies $companies, private CompanyInvites $invites, private CompanyTwoFactor $twoFactor) {}

    /** A empresa de quem administra (a rota já passou pelo `company.admin`). */
    public function companyOf(User $admin): Company
    {
        return $admin->companyMembership->company;
    }

    /** @return array{company: Company, members: Collection, invites: Collection, used: int, limit: int, twoFactorIds: list<int>, sectors: Collection} */
    public function overview(User $admin): array
    {
        $company = $this->companyOf($admin);

        return [
            'company' => $company,
            'members' => $company->members()->with('user:id,name,email')->orderByRaw("role = 'admin' desc")->orderBy('id')->get(),
            'invites' => $company->invites()->open()->orderBy('email')->get(),
            'used' => $this->invites->seatsTaken($company),
            'limit' => $this->companies->limit($company, 'accounts'),
            // As contas com a 2FA ligada (o selo na lista e quem pode ser redefinida, E1f).
            'twoFactorIds' => $this->twoFactor->enabledIds($company->id),
            // Os setores para pré-escolher no convite (E2a3), e o nome de cada um na lista de convites.
            'sectors' => $company->sectors()->orderByRaw('lower(name)')->pluck('name', 'id'),
        ];
    }
}
