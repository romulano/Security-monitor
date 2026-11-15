<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de alertas: o cliente escolhe o que cada app recebe e pode pausar
 * o envio sem desconectar. `muted_types` guarda os tipos desligados
 * (`news`, `radar`): vazio é tudo ligado, e um tipo novo chega ligado em
 * quem já tem canal. `paused_at` preenchido segura tudo, menos o teste.
 * Só acrescenta colunas com padrão: o app anterior continua funcionando
 * durante a troca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->jsonb('muted_types')->default('[]')->after('timezone');
            $table->timestamp('paused_at')->nullable()->after('muted_types');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn(['muted_types', 'paused_at']);
        });
    }
};
