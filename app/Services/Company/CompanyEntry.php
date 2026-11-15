<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Exceptions\ActionRefused;
use App\Mail\CompanyEntryLink;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Mail\Outbox;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A entrada da admin da empresa: a conta nasce com uma senha que ninguém conhece (Companies::create) e
 * recebe um link assinado, de 7 dias, para escolher a senha. Abrir o link confirma o e-mail e marca a
 * entrada. O link é de uso único: ele leva um carimbo da senha atual, e a senha muda ao usar (ou ao
 * reenviar, que sorteia outra), então todo link anterior deixa de valer. Só sai com a empresa ativa: com
 * a chave desligada ou suspensa a conta não entraria (EnsureCompanyAccountActive).
 */
class CompanyEntry
{
    public const LINK_DAYS = 7;

    /** A mesma resposta para link usado, vencido, adulterado ou de empresa inativa: não diz qual foi. */
    public const LINK_GONE = 'Este link já foi usado ou venceu. Se você já escolheu a senha, entre pelo login. Senão, peça um link novo ao suporte do Security Monitor: contato@example.com.';

    public function __construct(private Outbox $outbox, private Companies $companies, private CompanyAudit $audit) {}

    /** Manda o link à admin que ainda não entrou, se a empresa estiver ativa (na criação e ao ligar a chave). */
    public function invitePending(Company $company, ?User $by = null): bool
    {
        $member = $this->pendingAdmin($company);

        if ($member === null || ! $this->companies->isActive($company)) {
            return false;
        }

        $this->send($member->user, $company, $by);

        return true;
    }

    /** O "Reenviar o link" do painel: a senha sorteada de novo derruba os links de antes. */
    public function resend(Company $company, ?User $by = null): void
    {
        if (! $this->companies->isActive($company)) {
            throw new ActionRefused("Ligue a chave de {$company->name} (e tire a suspensão) antes de mandar o link.");
        }

        $joined = new ActionRefused("A admin de {$company->name} já entrou: o link não é mais necessário.");
        $member = $this->pendingAdmin($company) ?? throw $joined;

        // A mesma trava do complete(): se a admin escolheu a senha no meio, a senha dela fica e nada sai.
        $user = DB::transaction(function () use ($member, $joined) {
            $locked = User::query()->lockForUpdate()->findOrFail($member->user_id);

            if ($locked->companyMembership()->whereNull('joined_at')->whereNull('deactivated_at')->doesntExist()) {
                throw $joined;
            }

            $locked->forceFill(['password' => Str::password(40)])->save();

            return $locked;
        });

        $this->send($user, $company, $by);
    }

    /** O vínculo da conta do link, se ele ainda vale: empresa ativa, conta sem entrar e não desativada, carimbo atual. */
    public function pending(User $user, string $stamp): ?CompanyMember
    {
        $member = $user->companyMembership;

        if ($member === null || $member->joined_at !== null || $member->isDeactivated() || ! hash_equals($this->stamp($user), $stamp)) {
            return null;
        }

        return $this->companies->isActive($member->company) ? $member : null;
    }

    /** Escolhe a senha, confirma o e-mail, marca a entrada e entra na conta; dois envios juntos: só o primeiro passa. */
    public function complete(User $user, string $stamp, string $password, Session $session): User
    {
        $user = DB::transaction(function () use ($user, $stamp, $password) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $member = $this->pending($locked, $stamp) ?? throw new ActionRefused(self::LINK_GONE, route('login'));

            $locked->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'email_verified_at' => $locked->email_verified_at ?? now(),
            ])->save();
            $member->forceFill(['joined_at' => now()])->save();
            $this->audit->record($member->company_id, $locked, 'entry.joined', $locked);

            return $locked;
        });

        Auth::login($user);
        $session->regenerate();

        return $user;
    }

    private function pendingAdmin(Company $company): ?CompanyMember
    {
        return $company->members()->with('user')->where('role', CompanyRole::Admin)->whereNull('joined_at')->whereNull('deactivated_at')->first();
    }

    private function send(User $user, Company $company, ?User $by): void
    {
        $this->audit->record($company, $by, 'entry.sent', $user);
        $url = URL::temporarySignedRoute('company.entry', now()->addDays(self::LINK_DAYS), ['user' => $user->id, 'stamp' => $this->stamp($user)]);

        $this->outbox->send(new CompanyEntryLink($user->name, $company->name, $url, self::LINK_DAYS), $user->email, 'empresa-entrada', $user);
    }

    /** Muda junto com a senha: é o que faz o link valer uma vez só. */
    private function stamp(User $user): string
    {
        return substr(hash_hmac('sha256', 'empresa-entrada|'.$user->id.'|'.$user->getAuthPassword(), (string) config('app.key')), 0, 40);
    }
}
