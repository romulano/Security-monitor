<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O estado da varredura de chaves entre as etapas (`key_sites.scan_state`): a de 1.000 páginas
 * roda em jobs de 7 minutos, e cada um deixa aqui as filas, o que já leu e as chaves achadas
 * até ali para o seguinte continuar. Nula fora de uma varredura. Coluna nova e nula: compatível
 * com o código no ar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('key_sites', function (Blueprint $table) {
            $table->jsonb('scan_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('key_sites', function (Blueprint $table) {
            $table->dropColumn('scan_state');
        });
    }
};
