<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanSecuritySite;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Services\Security\SiteHost;
use App\Services\Security\SiteOwnership;

/**
 * Admin → Segurança: cadastrar, pausar, apagar e mandar verificar os sites que o admin quer vigiar.
 * O monitor é só do admin. O endereço passa por SiteHost (só nome DNS, nunca IP nem nome interno) e
 * o aceite fica com data e IP; a posse do site se prova depois (servidor do Security Monitor ou o TXT da conta).
 */
class SecuritySiteManager
{
    public function __construct(private SiteOwnership $ownership, private DomainOwnership $domains) {}

    public function create(User $admin, string $input, string $ip): SecuritySite
    {
        $host = SiteHost::normalize($input)
            ?? throw new ActionRefused('Endereço inválido. Informe só o nome do site, como exemplo.com.br (sem IP, porta nem nome interno).');

        if (SecuritySite::query()->where('host', $host)->exists()) {
            throw new ActionRefused("{$host} já está na lista.");
        }

        if (SecuritySite::query()->count() >= (int) config('secmon.security.max_sites')) {
            throw new ActionRefused('Limite de '.config('secmon.security.max_sites').' sites. Remova um para cadastrar outro.');
        }

        $site = SecuritySite::query()->create([
            'user_id' => $admin->id,
            'host' => $host,
            'enabled' => true,
            'interval_minutes' => (int) config('secmon.security.interval_minutes'),
            'consent_at' => now(),
            'consent_ip' => $ip,
            // O token da conta: o mesmo TXT de Notícias, do Radar e dos testes de segurança.
            'verify_token' => $this->domains->txtToken(DomainOwner::account($admin->id), $host),
            'next_check_at' => now(),
        ]);

        $this->domains->claim(DomainOwner::account($admin->id), $host, 'monitor', $site->verify_token);
        $this->ownership->refresh($site);

        return $site;
    }

    public function delete(SecuritySite $site): void
    {
        $site->delete();
    }

    /** Pausa ou retoma. Retomar agenda a verificação para já. Devolve se ficou ligado. */
    public function toggle(SecuritySite $site): bool
    {
        $enabled = ! $site->enabled;
        $site->forceFill(['enabled' => $enabled, 'next_check_at' => $enabled ? now() : $site->next_check_at])->save();

        return $enabled;
    }

    /** "Verificar agora": no máximo uma vez por hora por site, para o botão não virar ferramenta de carga. */
    public function runNow(SecuritySite $site): void
    {
        if (! $site->enabled) {
            throw new ActionRefused("{$site->host} está pausado. Retome a monitoria para verificar.");
        }

        $cooldown = (int) config('secmon.security.manual_cooldown_minutes');

        if ($site->manual_check_at && $site->manual_check_at->gt(now()->subMinutes($cooldown))) {
            $free = $site->manual_check_at->copy()->addMinutes($cooldown)->timezone('America/Sao_Paulo')->format('H:i');

            throw new ActionRefused("{$site->host} foi verificado à mão há pouco. A próxima verificação manual fica liberada às {$free}.");
        }

        $site->forceFill(['manual_check_at' => now()])->save();
        ScanSecuritySite::dispatch($site->id);
    }

    /** Confere a posse agora (depois de o admin criar o registro TXT). Devolve a prova achada ou nulo. */
    public function recheckOwnership(SecuritySite $site): ?string
    {
        return $this->ownership->refresh($site);
    }
}
