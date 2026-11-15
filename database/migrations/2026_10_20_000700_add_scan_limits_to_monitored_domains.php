<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carga no site do cliente: `last_scan_at` é a última varredura que de fato
 * pediu algo ao site (é ela que segura a próxima por 30 dias) e
 * `walls_retry_at` limita a releitura das páginas barradas a 1 por dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->timestampTz('last_scan_at')->nullable()->after('last_run_at');
            $table->timestampTz('walls_retry_at')->nullable()->after('last_scan_at');
        });

        // Quem já rodou conta como varrido nessa data.
        DB::table('monitored_domains')->whereNotNull('last_run_at')->update(['last_scan_at' => DB::raw('last_run_at')]);
    }

    public function down(): void
    {
        Schema::table('monitored_domains', function (Blueprint $table) {
            $table->dropColumn(['last_scan_at', 'walls_retry_at']);
        });
    }
};
