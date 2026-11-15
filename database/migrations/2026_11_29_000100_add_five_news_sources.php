<?php

use Database\Seeders\SourceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mais 5 fontes: Help Net Security, HackRead, Malwarebytes Labs,
 * Cybersecurity Dive e Datadog Security Labs. Só pega o que saiu na última
 * semana: o corte do CrawlerService pela data de cadastro deixa o arquivo dos
 * feeds de fora.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Instalação nova (e o banco dos testes) recebe as fontes pelo db:seed.
        if (! DB::table('sources')->exists()) {
            return;
        }

        // Só cria o que falta (o seeder não sobrescreve nem religa fonte).
        (new SourceSeeder)->run();
    }

    public function down(): void
    {
        DB::table('sources')
            ->whereIn('host', [
                'helpnetsecurity.com', 'hackread.com', 'malwarebytes.com',
                'cybersecuritydive.com', 'securitylabs.datadoghq.com',
            ])
            ->delete();
    }
};
