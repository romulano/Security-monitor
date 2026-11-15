<?php

namespace App\Console\Commands;

use App\Exceptions\ActionRefused;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Services\Domains\DomainOwnership;
use Illuminate\Console\Command;

/**
 * A posse de domínio no agendador, a cada 10 minutos (DomainOwnership):
 *
 * 1. procura o TXT dos domínios que ainda esperam a prova, por até 72 h: os de alertas com
 *    aceite, os de Radares já ativados (que esperam a posse para começar), os inscritos nos testes
 *    de segurança (pela regra da stack: o token da cópia, no próprio nome) e os nomes da conta que
 *    voltaram a esperar (a posse caiu);
 * 2. confere de novo, uma vez por dia, cada posse comprovada da conta. O TXT que some por 7 dias
 *    derruba a posse em Notícias, no Radar, no monitor e no webhook (cada um pausa o que dependia
 *    dela). A stack de testes não depende disto: ela confere ao vivo, em cada fase.
 */
class AlertsVerifyDomainsCommand extends Command
{
    protected $signature = 'secmon:alerts-verify-domains';

    protected $description = 'Procura o TXT dos domínios aguardando a prova de posse e reconfere as posses comprovadas';

    public function handle(DomainOwnership $ownership): int
    {
        $since = now()->subHours((int) config('secmon.ownership.verify_window_hours', 72));
        $checked = $verified = 0;

        $rows = MonitoredDomain::query()
            ->whereNull('verified_at')
            ->whereDoesntHave('pentestDomain')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('profile_id')->where('consent_at', '>=', $since))
                ->orWhereHas('lookalikeWatch', fn ($w) => $w->where('status', 'waiting')->where('completed_at', '>=', $since)))
            ->get();

        foreach ($rows as $row) {
            $checked++;
            $verified += $this->quietly(fn () => $ownership->verifyRow($row, $row->profile_id ? 'news' : 'radar'));
        }

        $copies = MonitoredDomain::query()
            ->whereNull('verified_at')
            ->whereHas('pentestDomain', fn ($p) => $p->where('created_at', '>=', $since))
            ->get();

        foreach ($copies as $copy) {
            $checked++;
            $verified += $ownership->verifyCopy($copy) ? 1 : 0;
        }

        // Os nomes que voltaram a esperar (a posse caiu) e que as linhas acima não comprovaram.
        foreach (OwnedDomain::query()->whereNull('verified_at')->where('waiting_since', '>=', $since)->get() as $owned) {
            if ($owned->refresh()->isVerified()) {
                continue;
            }

            $checked++;
            $verified += $this->quietly(fn () => $ownership->verify($ownership->ownerOfOwned($owned), $owned->domain, $owned->source) !== null);
        }

        $this->info("{$checked} domínio(s) conferido(s), {$verified} verificado(s).");

        // A reconferência diária, em lotes: as conferidas há mais tempo primeiro.
        $due = OwnedDomain::query()
            ->whereNotNull('verified_at')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subHours((int) config('secmon.ownership.recheck_hours', 24))))
            ->orderByRaw('checked_at asc nulls first')
            ->orderBy('id')
            ->limit((int) config('secmon.ownership.recheck_batch', 50))
            ->get();
        $lost = 0;

        foreach ($due as $owned) {
            $ownership->recheck($owned);
            $lost += $owned->isVerified() ? 0 : 1;
        }

        $this->info("{$due->count()} posse(s) reconferida(s), {$lost} caída(s).");

        return self::SUCCESS;
    }

    /**
     * Uma conferência que a regra da empresa recusa (o nome num setor que o item não usa, o limite de domínios, P1 e R5
     * do 5.3) não para a rodada: fica para quem configura ver na tela.
     */
    private function quietly(callable $check): int
    {
        try {
            return $check() ? 1 : 0;
        } catch (ActionRefused) {
            return 0;
        }
    }
}
