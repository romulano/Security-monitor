<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Mail\CompanyAccountDeactivated;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Admin\UserRemoval;
use App\Services\Mail\Outbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Remover uma conta da empresa (§4.6, D9): a conta fica desativada (sem acesso a nada, a sessão cai na hora) e não
 * vira pessoal; o que ela criou fica com a empresa. Quem administra pode reativar, se houver vaga, e depois de 30
 * dias desativada a conta é apagada. Quem administra não remove a si mesma: passa a administração antes.
 *
 * Os alertas de Notícias, os Radares e os monitoramentos do GitHub da empresa (e os domínios deles), a posse dos
 * domínios da empresa e os canais de aviso da empresa que ela conectou (com o histórico de envios) passam para a admin
 * antes de apagar (CompanyHandover, P7, E5a). Os canais pessoais que a conta tinha vão com a cascata.
 *
 * A conta com registro de teste de segurança (pentest_consents, pentest_runs) não é apagada: é a prova da autorização.
 * Ela fica desativada, e o --dry-run e o log a listam como mantida; a decisão final é da E6b, com a retenção da P14.
 */
class CompanyRemoval
{
    public const DAYS = 30;

    public function __construct(private CompanyInvites $invites, private Companies $companies, private UserRemoval $removal, private Outbox $outbox, private CompanyAudit $audit, private CompanyHandover $handover, private CompanyLimits $limits) {}

    public function deactivate(CompanyMember $member, ?User $by = null): void
    {
        $user = DB::transaction(function () use ($member, $by) {
            $locked = CompanyMember::query()->with('user', 'company')->lockForUpdate()->findOrFail($member->id);

            if ($locked->role === CompanyRole::Admin || $locked->isDeactivated()) {
                throw new ActionRefused('Esta conta não é removida por aqui.');
            }

            $locked->forceFill(['deactivated_at' => now()])->save();
            $this->audit->record($locked->company_id, $by, 'member.deactivated', $locked->user);
            // O "continuar conectado" de outros aparelhos também deixa de valer.
            $locked->user->forceFill(['remember_token' => Str::random(60)])->save();

            return $locked->user;
        });

        $user->endSessions();
        $this->outbox->send(new CompanyAccountDeactivated($user->name, $member->company->name, self::DAYS), $user->email, 'empresa-conta-desativada', $user);
        $this->limits->notify($member->company_id, 'accounts');
    }

    /** A desativada não ocupa vaga: reativar precisa de uma, contando os convites pendentes. */
    public function reactivate(CompanyMember $member, ?User $by = null): void
    {
        DB::transaction(function () use ($member, $by) {
            $company = Company::query()->lockForUpdate()->findOrFail($member->company_id);
            $locked = CompanyMember::query()->lockForUpdate()->findOrFail($member->id);

            if (! $locked->isDeactivated()) {
                throw new ActionRefused('Esta conta já está ativa.');
            }

            $taken = $this->invites->seatsTaken($company);
            $limit = $this->companies->limit($company, 'accounts');

            if ($taken >= $limit) {
                throw new ActionRefused("Não há vaga para reativar: a empresa já usa {$taken} de {$limit} contas, contando os convites pendentes. Cancele um convite pendente ou remova outra conta. Precisa de mais? Escreva para contato@example.com.");
            }

            $locked->forceFill(['deactivated_at' => null])->save();
            $this->audit->record($company, $by, 'member.reactivated', $locked->user);
            $this->limits->notify($company, 'accounts');
        });
    }

    /**
     * Apaga as contas de empresa desativadas há mais de 30 dias. Com a chave global desligada nada se apaga (o plano
     * fica parado), e a empresa suspensa tem os dados intactos (D7). Cada conta é conferida de novo, com a trava, logo
     * antes de apagar: a admin pode tê-la reativado depois da lista.
     *
     * @return int quantas
     */
    public function purge(bool $dryRun = false): int
    {
        if (! $this->companies->enabled()) {
            return 0;
        }

        $due = fn () => $this->overdue()->whereNotIn('user_id', $this->consents())->whereNotIn('user_id', $this->runs());
        $ids = $due()->pluck('user_id');

        foreach ($this->held() as $userId) {
            Log::info('secmon:company-purge: mantida, tem registro de teste de segurança', ['user_id' => $userId]);
        }

        if ($dryRun) {
            return $ids->count();
        }

        $done = 0;

        foreach ($ids as $userId) {
            try {
                $done += DB::transaction(function () use ($due, $userId) {
                    $member = $due()->where('user_id', $userId)->lockForUpdate()->first();

                    if ($member === null) {
                        return 0;
                    }

                    // O que ela criou na empresa fica com a empresa (P7): vai para a admin antes da cascata.
                    $handedOver = $this->handover->toAdmin($member);
                    $user = User::query()->findOrFail($userId);
                    // O registro fica, sem o nome e o e-mail da conta apagada (só o id), como na retenção da P14. A
                    // anonimização vem antes: depois de apagar, o convite aceito já não aponta para a conta.
                    $this->audit->anonymize($user);
                    $this->removal->erase($user);
                    $this->audit->record($member->company_id, null, 'member.purged', $user, CompanyAudit::REMOVED, array_filter(['itens passados à admin' => $handedOver]));

                    return 1;
                });
            } catch (Throwable $e) {
                Log::warning('Conta de empresa desativada não apagada', ['user_id' => $userId, 'erro' => $e->getMessage()]);
            }
        }

        return $done;
    }

    /** As contas vencidas que a poda mantém porque têm registro de teste de segurança. @return Collection<int, int> */
    public function held(): Collection
    {
        if (! $this->companies->enabled()) {
            return collect();
        }

        return $this->overdue()->where(fn ($query) => $query->whereIn('user_id', $this->consents())->orWhereIn('user_id', $this->runs()))->pluck('user_id');
    }

    /** Desativadas há mais de 30 dias, em empresa não suspensa (D7: a suspensa tem os dados intactos). */
    private function overdue(): Builder
    {
        return CompanyMember::query()->where('deactivated_at', '<', now()->subDays(self::DAYS))->whereHas('company', fn ($query) => $query->whereNull('suspended_at'));
    }

    private function consents(): QueryBuilder
    {
        return DB::table('pentest_consents')->select('user_id');
    }

    private function runs(): QueryBuilder
    {
        return DB::table('pentest_runs')->select('user_id');
    }
}
