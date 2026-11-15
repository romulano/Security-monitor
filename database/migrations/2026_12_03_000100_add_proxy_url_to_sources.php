<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O proxy de uma fonte, para os sites que bloqueiam o IP do servidor: o feed e
 * as páginas das matérias dela passam a sair por ele. A URL leva usuário e
 * senha, então vai criptografada pelo model (por isso `text`).
 *
 * Só uma coluna nova e nula: o código anterior no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('sources', function (Blueprint $table) {
            $table->text('proxy_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('proxy_url');
        });
    }
};
