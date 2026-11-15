<?php

use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A descoberta lê o MX e o SPF dos domínios, mas só reconhecia e-mail do
 * Google, da Microsoft e de quatro serviços de envio. Entram os provedores
 * comuns (Zoho, Proton, Mailgun, Postmark, SparkPost, Brevo, Mandrill,
 * RD Station) e a regra do Zendesk, que existia sem detecção.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Só cria o que falta (o seeder não sobrescreve).
        (new TechCatalogSeeder)->run();

        // O Zendesk já existia: ganha as regras só se o admin não pôs nenhuma.
        DB::table('tech_catalog')
            ->where('slug', 'zendesk')
            ->whereRaw("detect = '[]'::jsonb")
            ->update(['detect' => json_encode([
                ['type' => 'spf', 'pattern' => 'mail.zendesk.com'],
                ['type' => 'html', 'pattern' => 'static.zdassets.com'],
            ])]);
    }

    public function down(): void
    {
        DB::table('tech_catalog')
            ->whereIn('slug', ['zoho-mail', 'proton-mail', 'mailgun', 'postmark', 'sparkpost', 'brevo', 'mandrill', 'rd-station'])
            ->delete();
    }
};
