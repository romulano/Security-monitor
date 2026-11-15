<?php

namespace App\Services\Alerts\Webhook;

use App\Exceptions\ActionRefused;
use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\RegistrableDomain;

/**
 * A posse do domínio do webhook: o Security Monitor só chama uma URL cujo host a conta
 * comprovou ser dela, pelo registro secmon-verify=<token> no DNS. A posse é a da
 * conta (DomainOwnership), a mesma de Notícias, do Radar, do monitor e dos testes.
 *
 * - A prova vale para o próprio nome e para os subdomínios dele: quem comprovou
 *   empresa.com.br pode receber em hooks.empresa.com.br.
 * - O que a conta já comprovou em outro serviço vale aqui sem pedir de novo
 *   (proof()). O webhook guarda uma cópia da prova (`domain_id`), que acompanha a
 *   posse da conta (DomainOwnership::sync()): se ela cair, o envio para.
 * - Sem prova, a cópia nasce pendente no domínio principal do host, com o token
 *   da conta, e o botão Verificar procura o TXT (DomainOwnership: o TXT que chega
 *   por um CNAME não vale).
 */
class WebhookOwnership
{
    public function __construct(private DomainOwnership $domains) {}

    /** Na conta de empresa, o webhook só usa domínio que a empresa já comprovou (P2 do 5.3). */
    public const COMPANY_PROOF = 'O webhook de uma conta de empresa só chama um domínio que a empresa já comprovou: peça à admin ou a quem responde pelo setor para cadastrar e comprovar o domínio em Domínios da empresa.';

    /**
     * A prova que cobre o host, de qualquer serviço: a do nome mais próximo do host. Nula se não há. No webhook da
     * empresa (E5a), a da empresa que serve ao escopo dele: o geral só usa domínio sem setor; o do setor, também os do
     * setor (a regra de compatibilidade, P1 do 5.3). Na conta de empresa sem canal da empresa (antes da E5a), a da empresa
     * entre os domínios que a conta vê (DESENHO-5.3 §2.6).
     */
    public function proof(User $user, string $host, ?NotificationChannel $channel = null): ?OwnedDomain
    {
        return match (true) {
            (bool) $channel?->isCompany() => $this->domains->proof(self::ownerOf($channel), $host),
            $user->isCompanyAccount() => $this->domains->visibleProof($user, $host),
            default => $this->domains->proof(DomainOwner::account($user->id), $host),
        };
    }

    /** O dono da posse de um webhook da empresa: a empresa, com o setor do canal (nulo nos gerais). */
    public static function ownerOf(NotificationChannel $channel): DomainOwner
    {
        return DomainOwner::company((int) $channel->company_id, (int) $channel->user_id, $channel->sector_id);
    }

    /** O host é o domínio comprovado ou um subdomínio dele? */
    public static function covers(MonitoredDomain $domain, string $host): bool
    {
        return $host === $domain->domain || str_ends_with($host, '.'.$domain->domain);
    }

    /** Onde o cliente põe o TXT quando nada da conta cobre o host: o domínio principal (vale para os subdomínios). */
    public static function nameFor(string $host): string
    {
        return RegistrableDomain::of($host) ?? $host;
    }

    /**
     * Liga o webhook à prova do host dele: a cópia de uma prova da conta (o webhook fica ativo) ou uma
     * cópia pendente para o cliente comprovar. A cópia anterior, que não cobre mais o host, sai.
     */
    public function attach(NotificationChannel $channel, User $user): void
    {
        $host = (string) $channel->webhook_host;
        $current = $channel->domain;

        if ($current && self::covers($current, $host) && ($current->isVerified() || ! $this->proof($user, $host, $channel))) {
            $channel->forceFill(['status' => $current->isVerified() ? self::activeStatus($channel) : 'pending'])->save();

            return;
        }

        $source = $this->proof($user, $host, $channel);

        // A conta de empresa não cadastra domínio da empresa pelo webhook: sem a prova da empresa, recusa (P2 do 5.3).
        if ($source === null && $user->isCompanyAccount()) {
            throw new ActionRefused(self::COMPANY_PROOF);
        }

        $name = $source?->domain ?? self::nameFor($host);

        $copy = MonitoredDomain::query()->create([
            'user_id' => $user->id,
            'domain' => $name,
            'verify_token' => $source?->proof_token ?? $this->domains->txtToken(DomainOwner::account($user->id), $name),
            'verified_at' => $source?->verified_at,
            'verify_checked_at' => $source?->checked_at,
        ]);
        if (! $user->isCompanyAccount()) {
            $this->domains->claim(DomainOwner::account($user->id), $name, 'webhook', $copy->verify_token);
        }

        $channel->forceFill(['domain_id' => $copy->id, 'status' => $source ? self::activeStatus($channel) : 'pending'])->save();
        $current?->delete();
    }

    /**
     * Botão "Verificar": vale a prova que a conta já tem em outro serviço (feita depois do cadastro do
     * webhook); sem ela, procura o TXT. Sem o TXT, recusa dizendo o que falta (ActionRefused).
     */
    public function verify(NotificationChannel $channel, User $user): void
    {
        $copy = $channel->domain;

        if (! $copy) {
            $this->attach($channel, $user);

            return;
        }

        if (! $copy->isVerified() && ($source = $this->proof($user, (string) $channel->webhook_host, $channel))) {
            $copy->forceFill(['domain' => $source->domain, 'verify_token' => $source->proof_token, 'verified_at' => $source->verified_at, 'verify_checked_at' => $source->checked_at])->save();
        } elseif ($user->isCompanyAccount()) {
            // A conta de empresa não procura TXT pelo webhook (o confirmRow cadastraria o nome): a prova vem da empresa.
            if (! $copy->isVerified()) {
                throw new ActionRefused(self::COMPANY_PROOF);
            }
        } else {
            $this->domains->confirmRow($copy, 'webhook');
        }

        if ($channel->status === 'pending') {
            $channel->forceFill(['status' => 'active'])->save();
        }
    }

    /**
     * Na hora do envio: a cópia da prova existe, está comprovada e cobre o host da URL. No pessoal, a cópia é da conta
     * dona do canal; no da empresa, quem cadastrou não importa (a cópia acompanha a posse da empresa, ownerOf), mas o
     * domínio ainda tem de servir ao escopo do canal (mudou de setor, saiu da empresa: para).
     */
    public function holds(NotificationChannel $channel): bool
    {
        $copy = $channel->domain;

        if ($copy === null || ! $copy->isVerified() || ! self::covers($copy, (string) $channel->webhook_host)) {
            return false;
        }

        return $channel->isCompany()
            ? $this->domains->proof(self::ownerOf($channel), (string) $channel->webhook_host) !== null
            : $copy->user_id === $channel->user_id;
    }

    /** O quadro "Comprove que o domínio é seu" (<x-domain-ownership>), o de todos os serviços, enquanto a prova falta. */
    public function screen(NotificationChannel $channel): ?array
    {
        $copy = $channel->domain;

        return $copy && ! $copy->isVerified() ? $this->domains->screenFor($copy) : null;
    }

    /** Comprovado, volta a "conectado"; o que estava parado pelas falhas segue parado até o teste dar certo. */
    private static function activeStatus(NotificationChannel $channel): string
    {
        return $channel->status === 'broken' ? 'broken' : 'active';
    }
}
