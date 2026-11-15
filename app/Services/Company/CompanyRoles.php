<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Mail\CompanyAdminTransferred;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Mail\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Os papéis na empresa (§4.3): quem administra muda o papel das outras contas (membro ou responsável do setor) e
 * passa a administração para outra conta. A empresa tem uma admin só (índice parcial): a troca rebaixa a antiga
 * para membro e promove a nova, nessa ordem, na mesma transação. Os setores acompanham o papel (Sectors::roleChanged).
 */
class CompanyRoles
{
    public function __construct(private Outbox $outbox, private CompanyAudit $audit, private Sectors $sectors) {}

    public function change(CompanyMember $member, CompanyRole $role, ?User $by = null): void
    {
        if ($role === CompanyRole::Admin) {
            throw new ActionRefused('Para pôr outra conta na administração, use "Passar a administração".');
        }

        DB::transaction(function () use ($member, $role, $by) {
            $locked = CompanyMember::query()->with('user')->lockForUpdate()->findOrFail($member->id);

            if ($locked->role === CompanyRole::Admin || $locked->isDeactivated()) {
                throw new ActionRefused('O papel desta conta não muda por aqui.');
            }

            if ($locked->role !== $role) {
                $this->audit->record($locked->company_id, $by, 'member.role', $locked->user, data: ['de' => $locked->role->label(), 'para' => $role->label()]);
                // Como membro, deixa de responder pelos setores (E2a).
                $this->sectors->roleChanged($locked, $role, $by);
            }

            $locked->forceFill(['role' => $role])->save();
        });
    }

    /** Passa a administração de `$admin` para a conta `$memberId` da mesma empresa, ativa e que já entrou. */
    public function transfer(User $admin, int $memberId): CompanyMember
    {
        $target = DB::transaction(function () use ($admin, $memberId) {
            $company = Company::query()->lockForUpdate()->findOrFail($admin->companyMembership?->company_id);
            $current = $company->members()->lockForUpdate()->where('user_id', $admin->id)->where('role', CompanyRole::Admin)->first()
                ?? throw new ActionRefused('Só quem administra a empresa passa a administração.');
            $target = $company->members()->lockForUpdate()->whereKey($memberId)->first();

            if ($target === null || $target->is($current) || $target->isDeactivated() || $target->joined_at === null) {
                throw new ActionRefused('Escolha uma conta ativa da empresa que já entrou no Security Monitor.');
            }

            $current->forceFill(['role' => CompanyRole::Member])->save();
            $target->forceFill(['role' => CompanyRole::Admin])->save();
            $this->audit->record($company, $admin, 'admin.transferred', $target->user);
            // A nova admin responde por todos os setores (D3): sai das linhas de setor que tinha.
            $this->sectors->roleChanged($target, CompanyRole::Admin, $admin);

            return $target;
        });

        // O aviso à nova admin e o registro à antiga, cada um no próprio e-mail.
        $company = $target->company->name;
        $new = $target->user;
        $this->outbox->send(new CompanyAdminTransferred($new->name, $company, $admin->name, $new->name, isNew: true), $new->email, 'empresa-administracao', $new);
        $this->outbox->send(new CompanyAdminTransferred($admin->name, $company, $admin->name, $new->name, isNew: false), $admin->email, 'empresa-administracao', $admin);

        return $target;
    }
}
