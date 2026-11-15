<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Acessos: de quem é o robô (OpenAI, Google, Slack...) e para que ele
 * lê o site (RobotCatalog::KINDS), pelo que ele declara no navegador. Fica
 * gravado com o acesso: o filtro e o quadro por dono e tipo somam milhares de
 * linhas sem rodar o catálogo em cada uma. Nada novo sobre pessoas: sai do
 * navegador que o acesso já tem.
 *
 * Duas colunas novas e vazias: compatível com o código anterior no ar. Os
 * acessos de antes são preenchidos na migration seguinte, numa transação à
 * parte, para a trava da tabela durar só este ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('page_views', function (Blueprint $table) {
            $table->string('bot_owner', 60)->nullable();
            $table->string('bot_kind', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('page_views', function (Blueprint $table) {
            $table->dropColumn(['bot_owner', 'bot_kind']);
        });
    }
};
