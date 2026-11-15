<?php

namespace Tests\Feature\Alerts;

use App\Models\MonitoredDomain;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Alerts\Webhook\WebhookSecret;

/** Webhooks prontos para os testes: o domínio comprovado, o canal e o DNS que responde com IP público. */
trait WebhookTestHelpers
{
    use AlertsTestHelpers;

    protected const HOOK = 'https://hooks.cliente.com.br/secmon';

    /** Um IP público qualquer (não é faixa de documentação, que o IpGuard recusa). */
    protected const PUBLIC_IP = '93.184.215.14';

    protected FakeDnsResolver $dns;

    protected function fakeDns(array $hosts = ['hooks.cliente.com.br' => [self::PUBLIC_IP]]): FakeDnsResolver
    {
        $this->dns = new FakeDnsResolver;

        foreach ($hosts as $host => $ips) {
            $this->dns->set($host, 'ips', $ips);
        }

        $this->app->instance(DnsResolver::class, $this->dns);

        return $this->dns;
    }

    /** A prova de posse que a conta já tem em outro serviço (aqui, a de um Radar), na posse da conta. */
    protected function proven(User $user, string $domain = 'cliente.com.br', string $token = 'tokdaconta1'): MonitoredDomain
    {
        return $this->proveRow(MonitoredDomain::query()->create([
            'user_id' => $user->id,
            'domain' => $domain,
            'verify_token' => $token,
            'verified_at' => now()->subDay(),
            'verify_checked_at' => now()->subDay(),
        ]));
    }

    /** Um webhook ativo, com a cópia da prova e (por padrão) um segredo gerado. */
    protected function webhook(User $user, array $attributes = [], string $url = self::HOOK): NotificationChannel
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $copy = MonitoredDomain::query()->create([
            'user_id' => $user->id, 'domain' => 'cliente.com.br', 'verify_token' => 'tokdaconta1', 'verified_at' => now()->subDay(),
        ]);

        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'webhook',
            'method' => 'json',
            'name' => 'Produção',
            'webhook_url' => $url,
            'webhook_host' => $host,
            'webhook_secret' => WebhookSecret::generate(),
            'webhook_secret_at' => now(),
            'domain_id' => $copy->id,
            'status' => 'active',
            'digest_days' => [1, 2, 3, 4, 5, 6, 7],
        ] + NotificationChannel::defaults());
    }
}
