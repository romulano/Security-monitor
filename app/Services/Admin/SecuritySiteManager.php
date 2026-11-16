<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanSecuritySite;
use App\Models\SecuritySite;
use App\Models\User;
use App\Services\Security\SiteHost;

/**
 * Admin → Segurança: cadastrar, pausar, apagar e mandar verificar os sites que o admin quer vigiar.
 * O monitor é só do admin. O endereço passa por SiteHost.
 */
class SecuritySiteManager
{
    public function create(User $admin, string $input, string $ip): SecuritySite
    {
        $host = SiteHost::normalize($input)
            ?? throw new ActionRefused('Endereço inválido. Informe o nome do site ou o IP.');

        if (SecuritySite::query()->where('host', $host)->exists()) {
            throw new ActionRefused("{$host} já está na lista.");
        }

        return SecuritySite::query()->create([
            'user_id' => $admin->id,
            'host' => $host,
            'enabled' => true,
            'interval_minutes' => (int) config('secmon.security.interval_minutes'),
            'consent_at' => now(),
            'consent_ip' => $ip,
            'next_check_at' => now(),
        ]);
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

    /** "Verificar agora". */
    public function runNow(SecuritySite $site): void
    {
        if (! $site->enabled) {
            throw new ActionRefused("{$site->host} está pausado. Retome a monitoria para verificar.");
        }

        $site->forceFill(['manual_check_at' => now()])->save();
        ScanSecuritySite::dispatch($site->id);
    }
}
