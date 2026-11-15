<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Acessos: cada página aberta no site (fora do admin), com quem abriu
 * (IP, país pela Cloudflare, navegador e a conta, se logada), a resposta e o
 * tempo que ela ficou aberta, que o navegador manda ao sair. Some em 6 meses,
 * o prazo da /privacidade para os registros de acesso.
 *
 * Tabela nova: compatível com o código anterior no ar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            // O que a página manda de volta com o tempo: não dá para adivinhar o de outra visita.
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('ip', 45);
            $table->char('country', 2)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('path', 500);
            $table->string('referer', 500)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at')->index();

            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
