<?php

use Database\Seeders\SourceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mais 30 fontes: laboratórios (CrowdStrike, Wiz, Citizen Lab, watchTowr...),
 * boletins de correção (Palo Alto, Fortinet, GitLab), CERT-FR, FBI IC3,
 * imprensa de nicho e pesquisadores independentes. Só pega o que saiu na
 * última semana: o corte do CrawlerService pela data de cadastro deixa o
 * arquivo dos feeds de fora.
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
                'crowdstrike.com', 'huntress.com', 'elastic.co', 'recordedfuture.com', 'volexity.com',
                'research.checkpoint.com', 'sekoia.com', 'wiz.io', 'aikido.dev', 'citizenlab.ca',
                'infoblox.com', 'flashpoint.io', 'infostealers.com', 'labs.watchtowr.com', 'thedfirreport.com',
                'zerodayinitiative.com', 'infosecurity-magazine.com', 'wired.com', 'hipaajournal.com',
                'industrialcyber.co', 'olhardigital.com.br', 'cert.cyber.gouv.fr', 'ic3.gov',
                'security.paloaltonetworks.com', 'fortiguard.fortinet.com', 'docs.gitlab.com', 'patchstack.com',
                'zetter-zeroday.com', 'blog.bushidotoken.net', 'objective-see.org',
            ])
            ->delete();
    }
};
