<?php

use App\Support\RobotCatalog;
use App\Support\UserAgents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os acessos gravados antes do dono e do tipo do robô: saem do navegador que
 * cada um já tem, uma vez por navegador distinto, num UPDATE por lote que
 * junta pela coluna. O robô do catálogo que o teste antigo não pegava (o
 * WhatsApp, o Claude-User, o meta-externalagent) passa a contar como robô.
 *
 * Ampliou o catálogo e quer que os acessos de antes acompanhem: o mesmo
 * UPDATE, numa migration nova.
 */
return new class extends Migration
{
    private const BATCH = 300;

    public function up(): void
    {
        $robots = [];

        foreach (DB::table('page_views')->whereNotNull('user_agent')->distinct()->pluck('user_agent') as $userAgent) {
            if ($robot = UserAgents::robot($userAgent)) {
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

        // Sem navegador nenhum: robô sem dono (o UserAgents::isBot já os marcava).
        DB::table('page_views')->whereNull('user_agent')->update(['is_bot' => true, 'bot_kind' => RobotCatalog::UNKNOWN]);
    }

    public function down(): void
    {
        // O is_bot fica: o robô que ele passou a marcar continua sendo robô.
        DB::table('page_views')->whereNotNull('bot_kind')->update(['bot_owner' => null, 'bot_kind' => null]);
    }
};
