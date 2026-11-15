<?php

use App\Support\RobotCatalog;
use App\Support\UserAgents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os acessos gravados antes do tipo "ferramenta de varredura": o navegador do
 * Nikto, do sqlmap e dos outros de RobotCatalog::SCANNERS (que passavam por
 * gente ou por robô desconhecido) e o do Censys e do Expanse viram robô desse
 * tipo, uma vez por navegador distinto, num UPDATE por lote que junta pela
 * coluna (como a 2026_11_22_000200).
 *
 * Repete também a limpeza da 2026_12_05_000100: o link assinado que o
 * container velho gravou enquanto o novo rodava aquela migration sai aqui.
 */
return new class extends Migration
{
    private const BATCH = 300;

    public function up(): void
    {
        $scanners = [];

        foreach (DB::table('page_views')->whereNotNull('user_agent')->distinct()->pluck('user_agent') as $userAgent) {
            if (($robot = UserAgents::robot($userAgent)) && $robot['kind'] === RobotCatalog::SCANNER) {
                $scanners[] = [$userAgent, $robot['owner']];
            }
        }

        foreach (array_chunk($scanners, self::BATCH) as $batch) {
            DB::update(
                'update page_views as p set is_bot = true, bot_owner = v.owner, bot_kind = ?'
                .' from (values '.implode(', ', array_fill(0, count($batch), '(?::text, ?::text)')).') as v(user_agent, owner)'
                .' where p.user_agent = v.user_agent',
                [RobotCatalog::SCANNER, ...array_merge(...$batch)],
            );
        }

        (require __DIR__.'/2026_12_05_000100_redact_secrets_in_page_views.php')->up();
    }

    public function down(): void
    {
        // O is_bot fica: a ferramenta continua sendo robô, só sem o tipo.
        DB::table('page_views')->where('bot_kind', RobotCatalog::SCANNER)->update(['bot_kind' => RobotCatalog::UNKNOWN]);
    }
};
