<?php

use Database\Seeders\SourceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Troca a CISA pela NCSC do Reino Unido e pela CERT-EU. A Akamai da CISA
 * responde 403 a todo cliente que não é navegador, então as duas fontes dela
 * só geravam erro a cada coleta. Elas são desligadas, não apagadas: as
 * matérias que já vieram de lá continuam com a fonte.
 */
return new class extends Migration
{
    private const NEW_HOSTS = ['ncsc.gov.uk', 'cert.europa.eu'];

    public function up(): void
    {
        // Instalação nova (e o banco dos testes) recebe as fontes pelo db:seed.
        if (! DB::table('sources')->exists()) {
            return;
        }

        DB::table('sources')->where('host', 'cisa.gov')->update(['enabled' => false, 'updated_at' => now()]);

        // Só cria o que falta (o seeder não sobrescreve nem religa fonte).
        (new SourceSeeder)->run();
    }

    public function down(): void
    {
        DB::table('sources')->whereIn('host', self::NEW_HOSTS)->delete();
        DB::table('sources')->where('host', 'cisa.gov')->update(['enabled' => true, 'updated_at' => now()]);
    }
};
