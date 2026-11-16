<?php

namespace App\Services\Keys;

use App\Exceptions\ActionRefused;
use App\Models\ExposedKey;
use App\Models\KeySite;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\User;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lista de Chaves expostas: os domínios herdados de outros serviços (Notícias, Radar, webhook, testes)
 * mais os cadastrados direto aqui. Cadastrar não varre: o "Ativar e varrer" de cada linha continua
 * sendo o passo que autoriza a navegação no site.
 */
final class KeyDomains
{
    public function __construct(private DomainOwnership $ownership) {}

    /**
     * Cadastra o domínio em Chaves (ou o devolve à lista, se tinha sido tirado).
     *
     * @return array{0: OwnedDomain, 1: bool} o domínio e se ele entrou agora na lista
     */
    public function add(User $user, ?string $typed): array
    {
        $name = DomainName::normalize($typed)
            ?? throw ValidationException::withMessages(['dominio' => 'Informe um domínio válido, como empresa.com.br.']);

        return DB::transaction(function () use ($user, $name) {
            $owned = $this->ownership->claim(DomainOwner::account($user->id), $name, 'keys');
            $entered = $owned->wasRecentlyCreated || $owned->keys_listing === OwnedDomain::KEYS_REMOVED;

            $owned->forceFill(['keys_listing' => OwnedDomain::KEYS_ADDED])->save();

            return [$owned, $entered];
        });
    }

    /**
     * Tira o domínio de Chaves: a varredura e as chaves que ela achou saem. Se nenhum outro serviço usa o nome,
     * sai o registro inteiro; senão ele só deixa de aparecer aqui (o Radar, o alerta ou o webhook continuam).
     */
    public function remove(OwnedDomain $owned): void
    {
        DB::transaction(function () use ($owned) {
            $site = KeySite::query()->where('owned_domain_id', $owned->id)->lockForUpdate()->first();

            if ($site?->scan_status === 'running') {
                throw new ActionRefused("A varredura de {$owned->domain} está rodando agora. Remova quando ela terminar.");
            }

            $site?->delete();
            ExposedKey::query()->where('owned_domain_id', $owned->id)->where('source', 'scan')->delete();

            $used = MonitoredDomain::query()->where('user_id', $owned->user_id)->where('domain', $owned->domain)->exists();

            $used ? $owned->forceFill(['keys_listing' => OwnedDomain::KEYS_REMOVED])->save() : $owned->delete();
        });
    }
}
