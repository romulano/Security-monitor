<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matérias apagadas pela fusão noturna. O uuid antigo continua de pé e
        // redireciona para a matéria nova (`case_id`); o título, a assinatura e
        // os CVEs seguem ajudando a coleta a achar a matéria nova. O snapshot
        // guarda o caso como era, com os artigos e os alertas que saíram dele.
        Schema::create('case_merges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('title');
            $table->text('fingerprint')->nullable();
            $table->jsonb('cves')->default('[]');
            $table->string('reason', 500)->nullable();
            $table->jsonb('snapshot')->default('{}');
            $table->timestampTz('merged_at');
            $table->timestamps();

            $table->index('case_id');
        });

        // Quando a IA escreveu o relatório que junta as fontes. Nulo = a matéria
        // mostra a análise da fonte mais recente, como sempre.
        Schema::table('cases', function (Blueprint $table) {
            $table->timestampTz('consolidated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('consolidated_at');
        });

        Schema::dropIfExists('case_merges');
    }
};
