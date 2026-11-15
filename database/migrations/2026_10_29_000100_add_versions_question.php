<?php

use Database\Seeders\InterviewQuestionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versões do que o cliente usa (PHP 8.2, Ubuntu 22.04). A IA do parecer sabia
 * a versão só quando o cliente a escrevia no motivo de uma avaliação; com ela
 * no perfil, dá para dizer se uma falha corrigida no 8.3.14 atinge quem usa 8.2.
 * A pergunta entra no fim do passo 6, depois das que já existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->json('tech_versions')->nullable(); // {slug do catálogo: "8.2"}
        });

        if (! DB::table('interview_questions')->where('key', 'versions')->exists()) {
            $last = (int) DB::table('interview_questions')->max('position');

            (new InterviewQuestionSeeder)->run();

            DB::table('interview_questions')->where('key', 'versions')->update(['position' => $last + 1]);
        }
    }

    public function down(): void
    {
        DB::table('alert_profile_answers')->where('question_key', 'versions')->delete();
        DB::table('interview_questions')->where('key', 'versions')->delete();

        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->dropColumn('tech_versions');
        });
    }
};
