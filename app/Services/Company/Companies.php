<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Domains\DomainTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * O básico do plano empresarial: criar a empresa junto com a conta admin dela, ler os limites
 * efetivos e dizer se a empresa está ativa. Ainda sem tela: o painel do admin do Security Monitor (E1b) e
 * o convite (E1c) usam daqui.
 */
class Companies
{
    public function __construct(private CompanyAudit $audit) {}

    /** A chave global (ENTERPRISE_ENABLED). Desligada, nenhuma empresa está ativa. */
    public function enabled(): bool
    {
        return (bool) config('secmon.enterprise.enabled', false);
    }

    /** Ativa = a chave global, a da empresa (a piloto primeiro, D12) e não suspensa (D7). */
    public function isActive(Company $company): bool
    {
        return $this->enabled() && $company->enterprise_enabled_at !== null && ! $company->isSuspended();
    }

    /**
     * Por que esta conta não pode usar o Security Monitor agora, ou nulo se pode. A conta pessoal sempre pode; a de
     * empresa só com a empresa ativa e sem ter sido desativada pela admin (§4.6, D7).
     */
    public function accessRefusal(User $user): ?string
    {
        // Sempre do banco: a suspensão ou a desativação vale já na próxima página.
        $member = CompanyMember::query()->with('company')->where('user_id', $user->id)->first();

        return match (true) {
            $member === null => null,
            $member->isDeactivated() => 'Esta conta foi desativada pela admin da empresa.',
            $member->company->isSuspended() => 'A sua empresa está suspensa no Security Monitor. Fale com a admin da empresa.',
            ! $this->isActive($member->company) => 'A sua empresa ainda não está liberada no Security Monitor.',
            default => null,
        };
    }

    /** O limite efetivo: o que o admin do Security Monitor ajustou para a empresa, ou o padrão da config. */
    public function limit(Company $company, string $name): int
    {
        if (! in_array($name, Company::LIMITS, true)) {
            throw new InvalidArgumentException("Limite de empresa desconhecido: {$name}");
        }

        $override = $company->getAttribute('limit_'.$name);
        $default = config("secmon.enterprise.limits.{$name}");

        if ($override === null && ! is_int($default)) {
            throw new InvalidArgumentException("Sem padrão na config para o limite de empresa {$name}");
        }

        return (int) ($override ?? $default);
    }

    /** As contas que ocupam o limite (R1, com a admin): as desativadas saem da conta. */
    public function accountsUsed(Company $company): int
    {
        return $company->members()->whereNull('deactivated_at')->count();
    }

    /**
     * Os limites da empresa, ajustados pelo admin do Security Monitor: nulo volta ao padrão da config. O de
     * contas não fica abaixo das contas que a empresa já tem (ninguém sai por causa do limite).
     *
     * @param  array<string, int|null>  $limits
     */
    public function setLimits(Company $company, array $limits, ?User $by = null): void
    {
        DB::transaction(function () use ($company, $limits, $by) {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            // Vazio volta ao padrão da config, e o padrão também não pode ficar abaixo das contas que a empresa tem.
            $accounts = array_key_exists('accounts', $limits) ? ($limits['accounts'] ?? (int) config('secmon.enterprise.limits.accounts')) : null;

            if ($accounts !== null && $accounts < ($used = $this->accountsUsed($locked))) {
                throw new ActionRefused("A empresa já tem {$used} conta(s): o limite de contas não pode ficar abaixo disso.");
            }

            foreach (Company::LIMITS as $name) {
                if (array_key_exists($name, $limits)) {
                    $locked->setAttribute('limit_'.$name, $limits[$name] === null ? null : (int) $limits[$name]);
                }
            }

            $changed = collect($locked->getDirty())->mapWithKeys(fn ($value, $column) => [Company::LIMIT_LABELS[substr($column, 6)] ?? $column => [$locked->getOriginal($column), $value]])->all();
            $locked->save();
            $company->setRawAttributes($locked->getAttributes(), true);

            if ($changed !== []) {
                $this->audit->record($locked, $by, 'company.limits', data: array_map(fn ($pair) => implode(' → ', array_map(fn ($v) => $v ?? 'padrão', $pair)), $changed));
            }
        });
    }

    /** Suspensa (D7): os dados ficam, o acesso e os envios param até reativar. Repetir não muda a data. */
    public function suspend(Company $company, ?User $by = null): void
    {
        $this->toggle($company, 'suspended_at', true, $by, 'company.suspended');
    }

    public function resume(Company $company, ?User $by = null): void
    {
        $this->toggle($company, 'suspended_at', false, $by, 'company.resumed');
    }

    /** A chave da empresa (D12): a piloto primeiro. Repetir não muda a data. */
    public function enable(Company $company, ?User $by = null): void
    {
        $this->toggle($company, 'enterprise_enabled_at', true, $by, 'company.enabled');
    }

    public function disable(Company $company, ?User $by = null): void
    {
        $this->toggle($company, 'enterprise_enabled_at', false, $by, 'company.disabled');
    }

    /** Liga ou desliga uma data da empresa; a auditoria só registra quando o estado muda. */
    private function toggle(Company $company, string $column, bool $on, ?User $by, string $action): void
    {
        if (($company->getAttribute($column) !== null) === $on) {
            return;
        }

        $company->forceFill([$column => $on ? now() : null])->save();
        $this->audit->record($company, $by, $action);
    }

    /**
     * A empresa e a conta admin dela, numa transação só: a admin nasce como conta de empresa (R12),
     * nunca é uma conta pessoal que já existia. A senha é aleatória e ninguém a conhece; a admin
     * define a dela pelo link que o painel manda (E1b). O e-mail fica por confirmar.
     */
    public function create(string $name, string $adminName, string $adminEmail, ?User $by = null): Company
    {
        $name = trim($name);
        $adminName = trim($adminName);
        $adminEmail = mb_strtolower(trim($adminEmail));

        if ($name === '' || $adminName === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Nome da empresa, nome e e-mail da admin são obrigatórios.');
        }

        $taken = 'Já existe uma conta com este e-mail. Uma conta pessoal não vira conta de empresa: use outro e-mail para a admin.';

        try {
            return $this->createIn($name, $adminName, $adminEmail, $taken, $by);
        } catch (UniqueConstraintViolationException) {
            // Dois pedidos juntos com o mesmo e-mail: o índice único de users barra o segundo.
            throw new ActionRefused($taken);
        }
    }

    private function createIn(string $name, string $adminName, string $adminEmail, string $taken, ?User $by): Company
    {
        return DB::transaction(function () use ($name, $adminName, $adminEmail, $taken, $by) {
            if (User::query()->whereRaw('lower(email) = lower(?)', [$adminEmail])->exists()) {
                throw new ActionRefused($taken);
            }

            $company = new Company(['name' => $name]);
            $company->forceFill(['domain_token' => $this->newToken(), 'require_2fa' => false])->save();

            $admin = User::forceCreate([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Str::password(40),
                'is_admin' => false,
            ]);

            $company->members()->forceCreate([
                'user_id' => $admin->id,
                'role' => CompanyRole::Admin,
                'invited_at' => now(),
            ]);
            $this->audit->record($company, $by, 'company.created', $admin);

            return $company;
        });
    }

    /** O token do TXT da empresa, no formato do de conta e diferente de todo token já usado (DomainTokens). */
    private function newToken(): string
    {
        return app(DomainTokens::class)->fresh();
    }
}
