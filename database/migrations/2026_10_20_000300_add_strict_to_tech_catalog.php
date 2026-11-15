<?php

use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tecnologias amplas ("Python-based malware", payload hospedado no GitHub,
 * "Linux botnet") aparecem no texto de quase toda notícia sem que a stack de
 * quem as usa seja afetada. Com `strict`, elas só contam quando a análise as
 * lista como produto afetado ou fornecedor. Calibrado com o acervo real:
 * sem isso, 17 de 42 alertas de um perfil típico vinham só de menção no texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_catalog', function (Blueprint $table) {
            $table->boolean('strict')->default(false)->after('detect');
        });

        DB::table('tech_catalog')->whereIn('slug', TechCatalogSeeder::STRICT)->update(['strict' => true]);
    }

    public function down(): void
    {
        Schema::table('tech_catalog', function (Blueprint $table) {
            $table->dropColumn('strict');
        });
    }
};
