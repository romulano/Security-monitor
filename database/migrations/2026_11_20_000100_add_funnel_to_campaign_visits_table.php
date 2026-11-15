<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Campanhas mede o funil por canal: além da campanha, o canal (utm_source,
 * ou "orgânico: <domínio>" para quem chega sem utm pelo link de um site conhecido),
 * a variação (utm_content), o site de onde veio (só o domínio), as primeiras páginas
 * e a última, e as datas do que a conta fez depois do cadastro: confirmou o e-mail,
 * ativou (e com o quê), voltou noutro dia (D1) e uma semana depois (D7). Continua
 * sem IP, sem navegador e sem id de sessão.
 *
 * Só colunas novas, com padrão ou nulas: o app antigo segue gravando durante a troca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_visits', function (Blueprint $table) {
            $table->string('channel', 120)->default('(sem canal)');
            $table->string('content', 100)->nullable();
            $table->string('referrer', 100)->nullable();
            $table->jsonb('first_pages')->nullable();
            $table->string('last_page', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('activated_by', 20)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('retained_at')->nullable();

            $table->index(['channel', 'created_at']);
        });

        DB::table('campaign_visits')->whereNotNull('source')->update(['channel' => DB::raw('source')]);
        // Os marcos depois do cadastro (e-mail, ativação, volta) não são preenchidos para trás: o uso
        // novo dos dados da conta começa com a /privacidade que fala dele.
    }

    public function down(): void
    {
        Schema::table('campaign_visits', function (Blueprint $table) {
            $table->dropIndex(['channel', 'created_at']);
            $table->dropColumn([
                'channel', 'content', 'referrer', 'first_pages', 'last_page',
                'verified_at', 'activated_at', 'activated_by', 'returned_at', 'retained_at',
            ]);
        });
    }
};
