<?php

use Database\Seeders\InterviewQuestionSeeder;
use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * A entrevista não funciona sem catálogo e perguntas, e o entrypoint só roda
 * migrations: semeia aqui. Os seeders só criam o que falta, então rodar de
 * novo (ou o db:seed) não apaga edições feitas no admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TechCatalogSeeder)->run();
        (new InterviewQuestionSeeder)->run();
    }

    public function down(): void
    {
        // As tabelas somem no down da migration anterior.
    }
};
