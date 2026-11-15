<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uma empresa pode ter mais de um alerta (um por produto ou site), cada um
 * com a própria entrevista, domínio e histórico. O Slack continua um por
 * cliente: os alertas dele chegam no mesmo canal, cada um com o seu nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->index('user_id');
            $table->string('name', 80)->nullable();
        });

        if (! DB::table('interview_questions')->where('key', 'alert_name')->exists()) {
            DB::table('interview_questions')->insert([
                'step' => 1,
                'key' => 'alert_name',
                'prompt' => 'Nome deste alerta',
                'help' => 'Para diferenciar quando a empresa tem mais de um produto ou site. Ex.: Loja virtual, App, Portal do cliente.',
                'kind' => 'text',
                'options' => '[]',
                'required' => false,
                'active' => true,
                'is_system' => true,
                // Antes do nome da empresa (posição 1).
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('interview_questions')->where('key', 'alert_name')->delete();

        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });
    }
};
