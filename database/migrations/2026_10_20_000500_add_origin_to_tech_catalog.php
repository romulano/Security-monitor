<?php

use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itens que a descoberta cadastra sozinha (sinal fora do catálogo) ficam
 * marcados com `origin = discovery` e o domínio onde apareceram primeiro, para
 * o admin revisar. Entram também regras e itens novos para os sinais que a
 * descoberta passou a ler (TXT de verificação, CNAME, meta generator, scripts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_catalog', function (Blueprint $table) {
            $table->string('origin')->default('catalog')->after('active')->index(); // catalog | discovery
            $table->string('discovered_on')->nullable()->after('origin');
        });

        (new TechCatalogSeeder)->run();

        // Regras novas em itens que já existiam, sem duplicar nem apagar as do admin.
        foreach ([
            'm365' => ['type' => 'txt', 'pattern' => 'MS=ms'],
            'atlassian' => ['type' => 'txt', 'pattern' => 'atlassian-domain-verification'],
            'wordpress' => ['type' => 'generator', 'pattern' => 'WordPress'],
            'drupal' => ['type' => 'generator', 'pattern' => 'Drupal'],
            'joomla' => ['type' => 'generator', 'pattern' => 'Joomla'],
            'vercel' => ['type' => 'cname', 'pattern' => 'vercel-dns.com'],
            'shopify' => ['type' => 'cname', 'pattern' => 'myshopify.com'],
        ] as $slug => $rule) {
            $current = json_decode((string) DB::table('tech_catalog')->where('slug', $slug)->value('detect'), true);

            // Comparação sem ordem de chaves: o jsonb reordena.
            if (is_array($current) && ! in_array($rule, $current)) {
                DB::table('tech_catalog')->where('slug', $slug)->update(['detect' => json_encode(array_merge($current, [$rule]))]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tech_catalog')->where('origin', 'discovery')->delete();

        Schema::table('tech_catalog', function (Blueprint $table) {
            $table->dropColumn(['origin', 'discovered_on']);
        });
    }
};
