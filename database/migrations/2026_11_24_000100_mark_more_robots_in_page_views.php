<?php

use App\Support\UserAgents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os robôs que o catálogo ganhou em 2026-11-01 (o leitor em voz alta do
 * Google, o Apache Nutch, o Heritrix, as varreduras e bibliotecas): os
 * acessos já gravados com esses navegadores passavam como gente e saem de
 * "Só pessoas", com o dono e o tipo. Só os navegadores que têm um desses
 * nomes; os outros ficam como estão (a 2026_11_22_000200 já os marcou).
 */
return new class extends Migration
{
    private const BATCH = 300;

    /** Os nomes acrescentados ao RobotCatalog nesta leva. */
    private const NAMES = [
        'Google-Read-Aloud', 'GoogleImageProxy', 'Google Favicon', 'Nutch', 'heritrix', 'archive.org_bot', 'CensysInspect',
        'Expanse, a Palo Alto Networks company', 'zgrab', 'Nuclei', 'Nmap Scripting Engine', 'masscan', 'fasthttp', 'PycURL',
    ];

    public function up(): void
    {
        $robots = [];

        foreach (DB::table('page_views')->whereNotNull('user_agent')->distinct()->pluck('user_agent') as $userAgent) {
            $named = array_filter(self::NAMES, fn (string $name) => stripos($userAgent, $name) !== false);

            if ($named !== [] && ($robot = UserAgents::robot($userAgent))) {
                $robots[] = [$userAgent, $robot['owner'], $robot['kind']];
            }
        }

        foreach (array_chunk($robots, self::BATCH) as $batch) {
            DB::update(
                'update page_views as p set is_bot = true, bot_owner = v.owner, bot_kind = v.kind'
                .' from (values '.implode(', ', array_fill(0, count($batch), '(?::text, ?::text, ?::text)')).') as v(user_agent, owner, kind)'
                .' where p.user_agent = v.user_agent',
                array_merge(...$batch),
            );
        }
    }

    public function down(): void
    {
        // Fica: o robô que passou a ser reconhecido continua sendo robô.
    }
};
