<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O teto de convites por empresa a cada 24 horas (convidar e reenviar), ajustável pelo admin do Security Monitor como os outros
 * limites: nulo = o padrão de `secmon.enterprise.limits.daily_invites`. Sem ele, a admin mandava dezenas de e-mails a
 * terceiros em minutos pelo servidor de envio do Security Monitor (Red Team da E1c). Só coluna nova e nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('limit_daily_invites')->nullable();
        });

        DB::statement('alter table companies add constraint companies_limit_daily_invites_check check (limit_daily_invites >= 0)');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('limit_daily_invites');
        });
    }
};
