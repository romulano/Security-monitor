<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respostas escritas na entrevista ("Outro"): o que o cliente digitou e os
 * termos de busca em inglês que a IA gerou para ele. Uma linha por resposta
 * distinta, compartilhada entre clientes: "área adulta" de dois clientes é
 * traduzida uma vez só, e o admin corrige num lugar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_answer_terms', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);            // sector | region | attack_type | tech
            $table->string('label', 120);          // como o primeiro cliente escreveu
            $table->string('normalized', 120);     // minúsculo, sem acento
            $table->json('terms')->nullable();     // termos de busca (inglês)
            $table->json('codes')->nullable();     // regiões: códigos ISO dos países
            $table->string('status', 20)->default('pending'); // pending | ready | failed
            $table->text('error')->nullable();
            $table->boolean('edited_by_admin')->default(false);
            $table->timestamps();

            $table->unique(['kind', 'normalized']);
        });

        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->json('custom_answers')->nullable(); // {pergunta: [o que o cliente escreveu]}
        });
    }

    public function down(): void
    {
        Schema::table('alert_profiles', function (Blueprint $table) {
            $table->dropColumn('custom_answers');
        });

        Schema::dropIfExists('custom_answer_terms');
    }
};
