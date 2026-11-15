<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Acessos: o que o site recusa (4xx e 5xx, de qualquer método) ou que
 * tem cara de ataque (AttackSignatures), com o método e a resposta. Sob
 * inundação, passado o teto por minuto, fica só a contagem (access_event_overflow,
 * uma linha por minuto). As colunas auth e account_hmac são das tentativas
 * recusadas de login e cadastro. Some em 6 meses, como os registros de acesso.
 *
 * O nome security_events já é do monitor de segurança dos sites dos clientes.
 * Só tabelas novas: compatível com o código anterior no ar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('created_at')->index();
            $table->string('ip', 45);
            $table->char('country', 2)->nullable();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            // O método de verdade (getRealMethod), não o do _method do formulário.
            $table->string('method', 16);
            $table->string('path', 500);
            $table->string('route', 80)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('category', 24)->nullable();
            $table->string('auth', 24)->nullable();
            $table->char('account_hmac', 32)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('bot_kind', 16)->nullable();
            $table->string('referer', 500)->nullable();
            // O acesso da mesma requisição em page_views (o 404 de página), sem FK: cada um tem a sua poda.
            $table->unsignedBigInteger('page_view_id')->nullable();

            $table->index(['ip', 'created_at']);
        });

        DB::statement('create index access_events_account_hmac_created_at_index on access_events (account_hmac, created_at) where account_hmac is not null');

        Schema::create('access_event_overflow', function (Blueprint $table) {
            $table->timestampTz('minute')->primary();
            $table->unsignedInteger('dropped');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_event_overflow');
        Schema::dropIfExists('access_events');
    }
};
