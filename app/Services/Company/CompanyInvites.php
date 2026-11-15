<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Mail\CompanyInviteAccepted;
use App\Mail\CompanyInviteLink;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\Mail\Outbox;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * O convite da empresa (§4.2, R12, D4): a admin convida um e-mail, que recebe um link de 7 dias. Aceitar cria a
 * conta e o vínculo na mesma transação e nunca liga uma conta que já existe (A8): o e-mail de uma conta do Security Monitor
 * (pessoal ou de outra empresa) é recusado, com a explicação de usar outro e-mail.
 *
 * O limite de contas conta as contas ativas e os convites pendentes (abertos e no prazo): cada convite reserva
 * uma vaga, então a admin nunca manda mais convites do que cabem e quem aceita nunca encontra a empresa cheia
 * por causa de outro convite. O convite vencido ou cancelado libera a vaga.
 */
class CompanyInvites
{
    public const DAYS = 7;

    public const EMAIL_TAKEN = 'Este e-mail já tem uma conta no Security Monitor. Uma conta pessoal não entra em empresa e nada dela migra: convide outro e-mail, por exemplo o do trabalho.';

    public const LINK_GONE = 'Este convite já foi usado, foi cancelado ou venceu. Se você já aceitou, entre pelo login. Senão, peça um convite novo a quem administra a conta da empresa.';

    public function __construct(private Companies $companies, private Outbox $outbox, private CompanyAudit $audit, private Sectors $sectors, private CompanyLimits $limits) {}

    /** Contas ativas + convites pendentes: o que conta no limite de contas. */
    public function seatsTaken(Company $company): int
    {
        return $this->companies->accountsUsed($company) + $company->invites()->pending()->count();
    }

    /** @param  list<int>  $sectorIds  os setores em que a conta entra no aceite (E2a3), todos desta empresa */
    public function invite(Company $company, User $by, string $email, CompanyRole $role, array $sectorIds = []): CompanyInvite
    {
        // Em minúsculas pelo lower() do banco, o mesmo de toda comparação de e-mail: o mb_strtolower do PHP discorda
        // dele fora do ASCII (o İ, por exemplo; F3 do Red Team).
        // O serviço confere o formato também (como o Companies::create): o aceite cria a conta com este e-mail.
        if (mb_strlen(trim($email)) > 255 || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            throw new ActionRefused('Este e-mail não é válido.');
        }

        $email = (string) DB::scalar('select lower(?)', [trim($email)]);

        if ($role === CompanyRole::Admin) {
            throw new ActionRefused('A empresa tem uma admin só: convide como membro ou responsável do setor.');
        }

        $this->ensureDailyRoom($company);

        [$invite, $token] = DB::transaction(function () use ($company, $by, $email, $role, $sectorIds) {
            $locked = $this->lockSeats($company);
            $sectors = $this->sectors->pick($locked, $sectorIds);

            if (User::query()->whereRaw('lower(email) = lower(?)', [$email])->exists()) {
                throw new ActionRefused(self::EMAIL_TAKEN);
            }

            if ($locked->invites()->open()->whereRaw('lower(email) = lower(?)', [$email])->exists()) {
                throw new ActionRefused('Já existe um convite aberto para este e-mail: use Reenviar.');
            }

            $invite = new CompanyInvite;
            $token = $this->renew($invite->forceFill(['company_id' => $locked->id, 'email' => $email, 'role' => $role, 'invited_by' => $by->id, 'sector_ids' => $sectors->keys()->all()]));
            $this->audit->record($locked, $by, 'invite.sent', targetLabel: $email, data: ['papel' => $role->label()] + $this->sectorNames($sectors->values()->all()));

            return [$invite, $token];
        });

        $this->send($invite, $token);
        $this->limits->notify($company, 'accounts');

        return $invite;
    }

    /** Token e prazo novos (o link anterior deixa de valer); o vencido precisa de vaga de novo. */
    public function resend(CompanyInvite $invite, ?User $by = null): void
    {
        $this->ensureDailyRoom($invite->company);

        $token = DB::transaction(function () use ($invite, $by) {
            $this->lockSeats($invite->company, mine: $invite->isPending() ? 1 : 0);
            $locked = CompanyInvite::query()->open()->lockForUpdate()->find($invite->id)
                ?? throw new ActionRefused('Este convite já foi aceito ou cancelado.');

            $this->audit->record($locked->company_id, $by, 'invite.resent', targetLabel: $locked->email);

            return $this->renew($locked);
        });

        $this->send($invite->refresh(), $token);
    }

    public function cancel(CompanyInvite $invite, ?User $by = null): void
    {
        if ($invite->newQuery()->open()->whereKey($invite->id)->update(['revoked_at' => now()]) > 0) {
            $this->audit->record($invite->company_id, $by, 'invite.cancelled', targetLabel: $invite->email);
            $this->limits->notify($invite->company_id, 'accounts');
        }
    }

    /** O convite do link, se ele ainda vale: aberto, no prazo e com a empresa ativa. */
    public function findPending(string $token): ?CompanyInvite
    {
        $invite = CompanyInvite::query()->pending()->with('company')->where('token_hash', hash('sha256', $token))->first();

        return $invite && $this->companies->isActive($invite->company) ? $invite : null;
    }

    /**
     * Aceitar: numa transação, a conta nova (com o e-mail do convite, já confirmado: o link chegou nele), o vínculo
     * com o papel do convite e o convite marcado como aceito. Dois aceites juntos: só o primeiro passa.
     */
    public function accept(string $token, string $name, string $password, Session $session): User
    {
        [$user, $invite] = DB::transaction(function () use ($token, $name, $password) {
            // A empresa antes do convite, na mesma ordem do convidar e do reenviar (sem deadlock).
            $found = $this->findPending($token) ?? throw new ActionRefused(self::LINK_GONE, route('login'));
            $company = $this->lockSeats($found->company, accountsOnly: true);
            $invite = CompanyInvite::query()->pending()->lockForUpdate()->find($found->id) ?? throw new ActionRefused(self::LINK_GONE, route('login'));

            if (User::query()->whereRaw('lower(email) = lower(?)', [$invite->email])->exists()) {
                throw new ActionRefused(self::EMAIL_TAKEN, route('login'));
            }

            try {
                $user = DB::transaction(fn () => User::forceCreate([
                    'name' => $name,
                    'email' => $invite->email,
                    'password' => $password,
                    'is_admin' => false,
                    'email_verified_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new ActionRefused(self::EMAIL_TAKEN, route('login'));
            }

            $member = $company->members()->forceCreate(['user_id' => $user->id, 'role' => $invite->role, 'invited_at' => $invite->created_at, 'joined_at' => now()]);
            // Os setores do convite que ainda existem (E2a3), na mesma transação.
            $sectors = $this->sectors->enterInvited($member, is_array($invite->sector_ids) ? $invite->sector_ids : []);
            $invite->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            $this->audit->record($company, $user, 'invite.accepted', $user, data: ['papel' => $invite->role->label()] + $this->sectorNames($sectors));

            return [$user, $invite];
        });

        Auth::login($user);
        $session->regenerate();
        $this->tellTheAdmin($invite, $user);

        return $user;
    }

    /**
     * Trava a empresa e confere a vaga: as contas ativas e os convites pendentes, menos a vaga que já é do próprio
     * convite (`$mine`), ficam abaixo do limite. No aceite contam só as contas (`$accountsOnly`): o convite já tem a
     * vaga dele, mas a empresa pode ter enchido (o limite reduzido, por exemplo).
     */
    private function lockSeats(Company $company, int $mine = 0, bool $accountsOnly = false): Company
    {
        $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
        $taken = $accountsOnly ? $this->companies->accountsUsed($locked) : $this->seatsTaken($locked) - $mine;
        $limit = $this->companies->limit($locked, 'accounts');

        if ($taken >= $limit) {
            throw new ActionRefused("A empresa já usa {$taken} de {$limit} contas, contando os convites pendentes. Para convidar outra pessoa, remova uma conta ou cancele um convite pendente. Precisa de mais? Escreva para contato@example.com.");
        }

        return $locked;
    }

    /** @param  list<string>  $names  os setores, para a auditoria (só quando há algum) */
    private function sectorNames(array $names): array
    {
        return $names === [] ? [] : ['setores' => implode(', ', $names)];
    }

    /** O teto de convites (convidar e reenviar) da empresa nas últimas 24 horas; só o que saiu por e-mail conta. */
    private function ensureDailyRoom(Company $company): void
    {
        $limit = $this->companies->limit($company, 'daily_invites');

        if (RateLimiter::tooManyAttempts('empresa-convites:'.$company->id, $limit)) {
            throw new ActionRefused("A empresa já mandou {$limit} convites nas últimas 24 horas. Tente de novo mais tarde ou peça um teto maior ao suporte do Security Monitor.");
        }
    }

    private function renew(CompanyInvite $invite): string
    {
        $token = Str::random(48);
        $invite->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::DAYS)])->save();

        return $token;
    }

    private function send(CompanyInvite $invite, string $token): void
    {
        $mail = new CompanyInviteLink($invite->company->name, $invite->inviter?->name, route('company.invite', $token), self::DAYS);
        $this->outbox->send($mail, $invite->email, 'empresa-convite');
        RateLimiter::hit('empresa-convites:'.$invite->company_id, 86400);
    }

    private function tellTheAdmin(CompanyInvite $invite, User $user): void
    {
        $admin = $invite->company->members()->with('user')->where('role', CompanyRole::Admin)->whereNull('deactivated_at')->first()?->user;

        if ($admin !== null) {
            $this->outbox->send(new CompanyInviteAccepted($admin->name, $invite->company->name, $user->name, $user->email), $admin->email, 'empresa-convite-aceito', $admin);
        }
    }
}
