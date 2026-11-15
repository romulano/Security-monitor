<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Acessos: o código do aparelho de quem navega sem login, só para
 * segurança (reconhecer o atacante que troca de IP; /privacidade, seção 2).
 * Uma linha por código, IP e dia. Sem vínculo com conta, de propósito: nada de
 * user_id, token nem page_view_id (um join com page_views levaria à conta).
 *
 * Só uma tabela nova: o código anterior no ar não a vê.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('device_sightings', function (Blueprint $table) {
            $table->id();
            // HMAC (APP_KEY) do hash que o navegador manda; o hash cru não fica.
            $table->char('fingerprint', 64);
            $table->string('ip', 45);
            $table->date('day');
            $table->timestampTz('first_seen');
            $table->timestampTz('last_seen');
            $table->integer('hits')->default(1);
            $table->char('country', 2)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->unique(['fingerprint', 'ip', 'day']);
            $table->index(['ip', 'day']);
            $table->index(['fingerprint', 'last_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sightings');
    }
};
