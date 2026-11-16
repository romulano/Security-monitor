<?php

namespace App\Services\Alerts\Webhook;

use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\RegistrableDomain;

/**
 * A posse do domínio do webhook. A posse é a da conta (DomainOwnership), a mesma de Notícias, do
 * Radar, do monitor e dos testes: instalação self-hosted, de um dono só, todo domínio nasce
 * comprovado, sem prova por registro TXT no DNS.
 *
 * - A prova vale para o próprio nome e para os subdomínios dele: quem cadastrou empresa.com.br pode
 *   receber em hooks.empresa.com.br.
 * - O que a conta já cadastrou em outro serviço vale aqui sem pedir de novo (proof()). O webhook
 *   guarda uma cópia da prova (`domain_id`), que acompanha a posse da conta (DomainOwnership::sync()).
 * - Sem prova em outro serviço, a cópia nasce no domínio principal do host, já comprovada.
 */
class WebhookOwnership
{
    public function __construct(private DomainOwnership $domains) {}

    /**
     * A prova que cobre o host, de qualquer serviço: a do nome mais próximo do host. Nula se não há.
     */
    public function proof(User $user, string $host, ?NotificationChannel $channel = null): ?OwnedDomain
    {
        return $this->domains->proof(DomainOwner::account($user->id), $host);
    }

    /** O host é o domínio comprovado ou um subdomínio dele? */
    public static function covers(MonitoredDomain $domain, string $host): bool
    {
        return $host === $domain->domain || str_ends_with($host, '.'.$domain->domain);
    }

    /** Sem prova em outro serviço, a cópia nasce aqui: o domínio principal (vale para os subdomínios). */
    public static function nameFor(string $host): string
    {
        return RegistrableDomain::of($host) ?? $host;
    }

    /**
     * Liga o webhook à prova do host dele: a cópia de uma prova da conta, se houver, ou uma cópia nova
     * — já comprovada, instalação de um dono só. A cópia anterior, que não cobre mais o host, sai.
     */
    public function attach(NotificationChannel $channel, User $user): void
    {
        $host = (string) $channel->webhook_host;
        $current = $channel->domain;

        if ($current && self::covers($current, $host)) {
            $channel->forceFill(['status' => self::activeStatus($channel)])->save();

            return;
        }

        $source = $this->proof($user, $host, $channel);
        $name = $source?->domain ?? self::nameFor($host);

        // Com prova de outro serviço, a cópia mostra a mesma data dela, não a de agora.
        $copy = MonitoredDomain::query()->create(['user_id' => $user->id, 'domain' => $name, 'verified_at' => $source?->verified_at]);
        $this->domains->claim(DomainOwner::account($user->id), $name, 'webhook');

        $channel->forceFill(['domain_id' => $copy->id, 'status' => self::activeStatus($channel)])->save();
        $current?->delete();
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

        return $copy->user_id === $channel->user_id;
    }

    /** Comprovado, volta a "conectado"; o que estava parado pelas falhas segue parado até o teste dar certo. */
    private static function activeStatus(NotificationChannel $channel): string
    {
        return $channel->status === 'broken' ? 'broken' : 'active';
    }
}
