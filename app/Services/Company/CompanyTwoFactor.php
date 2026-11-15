<?php

namespace App\Services\Company;

use App\Exceptions\ActionRefused;
use App\Mail\CompanyTwoFactorRequired;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Services\Mail\Outbox;
use Illuminate\Support\Str;

/**
 * A 2FA na empresa (R13, D11), pelas mãos de quem administra: exigir de todas
 * as contas (`require_2fa`; quem não tem a 2FA só chega à tela de configurar,
 * ver EnsureTwoFactorWhenRequired) e redefinir a 2FA de uma conta que perdeu
 * o celular. As rotas pedem a reautenticação; aqui, a conta alvo é procurada
 * só dentro da empresa de quem pede (a de outra empresa é 404).
 */
class CompanyTwoFactor
{
    public function __construct(private TwoFactor $twoFactor, private Outbox $outbox, private CompanyAudit $log) {}

    /** A empresa da conta exige a 2FA? (o admin do Security Monitor não é conta de empresa) */
    public static function requires(User $user): bool
    {
        return ! $user->is_admin && (bool) $user->companyMembership?->company?->require_2fa;
    }

    /** Os ids das contas da empresa que já ligaram a 2FA. @return list<int> */
    public function enabledIds(int $companyId): array
    {
        return UserTwoFactor::query()->whereNotNull('confirmed_at')
            ->whereIn('user_id', CompanyMember::query()->where('company_id', $companyId)->select('user_id'))
            ->pluck('user_id')->all();
    }

    /** Liga ou desliga a exigência. Ligar pede a 2FA de quem administra e avisa por e-mail as contas que ainda não a têm. */
    public function setRequired(User $admin, bool $required): void
    {
        $company = $admin->companyMembership->company;

        if ($required && ! TwoFactor::enabled($admin)) {
            throw new ActionRefused('Ligue a sua verificação em duas etapas antes de exigir das outras contas.');
        }

        if ($company->require_2fa === $required) {
            return;
        }

        $company->forceFill(['require_2fa' => $required])->save();
        $this->audit($admin, $required ? 'two_factor.required' : 'two_factor.optional');

        if ($required) {
            $enabled = $this->enabledIds($company->id);
            $company->members()->with('user')->whereNull('deactivated_at')->whereNotIn('user_id', $enabled)->get()
                ->each(fn (CompanyMember $member) => $this->outbox->send(
                    new CompanyTwoFactorRequired($member->user->name, $admin->name, $company->name, route('account.two-factor')),
                    $member->user->email, 'seguranca', $member->user,
                ));
        }
    }

    /**
     * Redefine a 2FA de uma conta da empresa que perdeu o celular (D11): o
     * segredo e os códigos são apagados, as sessões da conta caem, ela recebe
     * um e-mail e, se a empresa exige a 2FA, configura de novo no próximo acesso.
     * Só a admin com a própria 2FA ligada redefine. A própria 2FA
     * de quem administra não muda por aqui (para isso há Minha conta).
     */
    public function reset(User $admin, int $userId): User
    {
        $member = CompanyMember::query()->with('user')
            ->where('company_id', $admin->companyMembership->company_id)->where('user_id', $userId)->firstOrFail();

        if ($member->user_id === $admin->id) {
            throw new ActionRefused('A sua verificação em duas etapas muda em Minha conta → Verificação em duas etapas.');
        }

        // Como no exigir: tirar o segundo fator dos outros pede o da própria admin (K1 do Red Team). Sem ele, a
        // reautenticação é só a senha, e a senha roubada da admin bastava para derrubar a 2FA da empresa toda.
        if (! TwoFactor::enabled($admin)) {
            throw new ActionRefused('Ligue a sua verificação em duas etapas antes de redefinir a de outra conta.');
        }

        if (! $this->twoFactor->disable($member->user, 'reset')) {
            throw new ActionRefused("{$member->user->name} não tem a verificação em duas etapas ligada.");
        }

        // A redefinição é a resposta ao celular perdido, que costuma ter o Security Monitor aberto: as sessões e o
        // "Continuar conectado" da conta caem junto (K2 do Red Team). Ela entra de novo com a senha.
        $member->user->forceFill(['remember_token' => Str::random(60)])->save();
        $member->user->endSessions();

        $this->audit($admin, 'two_factor.reset', $member->user);

        return $member->user;
    }

    /** A Atividade da empresa (E1e): quem fez e, na redefinição, de quem era a 2FA. */
    private function audit(User $actor, string $action, ?User $target = null): void
    {
        $this->log->record($actor->companyMembership->company_id, $actor, $action, $target);
    }
}
