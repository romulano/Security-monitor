<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os endereços extras da varredura de chaves (`key_sites.extra_paths`): as páginas que o dono
 * lista para a varredura sempre visitar, as que nenhum link público, sitemap ou o Web Archive
 * mostram (um app em /app/…, um fluxo de cadastro). Até 50 caminhos ou URLs do domínio; nula,
 * nenhum. Coluna nova e nula: compatível com o código no ar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('key_sites', function (Blueprint $table) {
            $table->jsonb('extra_paths')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('key_sites', function (Blueprint $table) {
            $table->dropColumn('extra_paths');
        });
    }
};
