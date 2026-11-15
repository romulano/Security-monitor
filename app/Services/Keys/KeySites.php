<?php

namespace App\Services\Keys;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanSiteKeys;
use App\Models\KeySite;
use App\Models\OwnedDomain;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Os domínios comprovados ativados para a varredura de chaves: ativar (que já pede a 1ª), pedir de
 * novo ("Varrer agora", no máximo uma a cada 30 minutos), pausar, voltar, e a semanal.
 *
 * Só domínio com a posse em dia (comprovado e sem a reconferência ter perdido o TXT): é a única
 * autorização que a varredura pede, e ela é conferida de novo quando o job começa.
 */
final class KeySites
{
    public const EVERY_DAYS = 7;

    public const MANUAL_MINUTES = 30;

    /**
     * A varredura rodando sem sinal de vida por isso (o deploy ou um worker morto matou o job; ela grava de minuto em minuto):
     * continua do último ponto gravado, até 3 vezes; depois, fica com o que achou.
     */
    public const STALE_MINUTES = 10;

    public const MAX_RESUMES = 3;

    public static function provable(?OwnedDomain $owned): bool
    {
        return $owned !== null && $owned->isVerified() && $owned->unseen_since === null;
    }

    /** Ativa a varredura do domínio e pede a 1ª (ou só a pede de novo, se já estava ativa). */
    public function activate(OwnedDomain $owned, User $user, ?Carbon $now = null): KeySite
    {
        $now ??= now();
        self::refuseUnless(self::provable($owned), "A posse de {$owned->domain} não está comprovada: comprove pelo registro TXT antes de varrer.");

        $site = DB::transaction(function () use ($owned, $user) {
            $site = KeySite::query()->where('owned_domain_id', $owned->id)->lockForUpdate()->first()
                ?? KeySite::query()->create(['owned_domain_id' => $owned->id, 'user_id' => $user->id]);

            if (! $site->isActive()) {
                $site->forceFill(['status' => 'active'])->save();
            }

            return $site;
        });

        return $this->request($site, $now, manual: true);
    }

    /** "Varrer agora": recusa a que já está na fila ou rodando e a que foi pedida há menos de 30 minutos. */
    public function request(KeySite $site, ?Carbon $now = null, bool $manual = false): KeySite
    {
        $now ??= now();

        return DB::transaction(function () use ($site, $now, $manual) {
            $site = KeySite::query()->with('ownedDomain')->whereKey($site->id)->lockForUpdate()->firstOrFail();
            self::refuseUnless(self::provable($site->ownedDomain), 'A posse deste domínio não está mais comprovada: publique o registro TXT de novo.');
            self::refuseUnless($site->isActive(), 'A varredura deste domínio está pausada.');
            self::refuseUnless(! $site->isBusy(), 'Já tem uma varredura deste domínio na fila ou rodando.');
            self::refuseUnless(! $manual || $site->requested_at === null || $site->requested_at->lte($now->copy()->subMinutes(self::MANUAL_MINUTES)),
                'Este domínio foi varrido há pouco: dá para pedir de novo '.$site->requested_at?->copy()->addMinutes(self::MANUAL_MINUTES)->timezone('America/Sao_Paulo')->format('\à\s H:i').'.');

            $site->forceFill(['scan_status' => 'queued', 'requested_at' => $now])->save();
            ScanSiteKeys::dispatch($site->id)->afterCommit();

            return $site;
        });
    }

    /**
     * Os endereços extras que a varredura sempre visita (já conferidos pelo pedido: caminhos ou URLs do domínio). O
     * domínio que ainda não foi ativado ganha a linha dele, sem varredura pedida: o "Ativar e varrer" a pede.
     *
     * @param  list<string>  $paths
     */
    public function setPaths(OwnedDomain $owned, User $user, array $paths): KeySite
    {
        $site = KeySite::query()->firstOrCreate(['owned_domain_id' => $owned->id], ['user_id' => $user->id]);
        $site->forceFill(['extra_paths' => $paths === [] ? null : array_values($paths)])->save();

        return $site;
    }

    /** Pausa a varredura do domínio (a que nunca foi ativada não tem o que pausar). */
    public function pauseDomain(OwnedDomain $owned): void
    {
        $this->pause(KeySite::query()->where('owned_domain_id', $owned->id)->first() ?? throw new ActionRefused('A varredura deste domínio ainda não foi ativada.'));
    }

    public function resumeDomain(OwnedDomain $owned): void
    {
        $this->resume(KeySite::query()->where('owned_domain_id', $owned->id)->first() ?? throw new ActionRefused('A varredura deste domínio ainda não foi ativada.'));
    }

    public function pause(KeySite $site): void
    {
        $site->forceFill(['status' => 'paused'])->save();
    }

    public function resume(KeySite $site, ?Carbon $now = null): void
    {
        $site->forceFill(['status' => 'active', 'next_scan_at' => $site->next_scan_at ?? ($now ?? now())])->save();
    }

    /**
     * A semanal: cada site ativo com a vez vencida, a posse em dia e livre (o preso por um worker morto volta a ficar livre).
     *
     * @return int quantas foram pedidas
     */
    public function due(?Carbon $now = null): int
    {
        $now ??= now();
        foreach (KeySite::query()->where('scan_status', 'running')->where('started_at', '<', $now->copy()->subMinutes(self::STALE_MINUTES))->get() as $stuck) {
            $state = $stuck->scan_state;

            if (is_array($state) && ($state['resumes'] ?? 0) < self::MAX_RESUMES) {
                $state['resumes'] = ($state['resumes'] ?? 0) + 1;
                $stuck->forceFill(['scan_state' => $state, 'started_at' => $now])->save();
                ScanSiteKeys::dispatch($stuck->id, true);

                continue;
            }

            $stuck->forceFill(['scan_status' => 'idle', 'scan_state' => null, 'last_scan_at' => $now, 'last_result' => 'failed',
                'next_scan_at' => $now->copy()->addDays(self::EVERY_DAYS),
                'last_error' => 'A varredura foi interrompida algumas vezes e parou; as chaves achadas até ali ficaram.'])->save();
        }
        KeySite::query()->where('scan_status', 'queued')->where('requested_at', '<', $now->copy()->subMinutes(self::STALE_MINUTES * 4))
            ->update(['scan_status' => 'idle']);

        $count = 0;

        foreach (KeySite::query()->with('ownedDomain')->where('status', 'active')->where('scan_status', 'idle')
            ->where('next_scan_at', '<=', $now)->orderBy('next_scan_at')->limit(200)->get() as $site) {
            if (! self::provable($site->ownedDomain)) {
                $site->forceFill(['next_scan_at' => $now->copy()->addDay()])->save();

                continue;
            }

            $this->request($site, $now);
            $count++;
        }

        return $count;
    }

    private static function refuseUnless(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new ActionRefused($message);
        }
    }
}
