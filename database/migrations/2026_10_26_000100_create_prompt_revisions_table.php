<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada edição de um trecho de prompt no admin. O que está em uso é a
        // revisão mais nova do trecho; `body` nulo quer dizer "voltou ao
        // padrão" (resources/prompts). `base_hash` é o padrão em que a edição
        // se baseou: quando o código muda o padrão, o admin avisa.
        Schema::create('prompt_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->text('body')->nullable();
            $table->string('base_hash', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['key', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_revisions');
    }
};
