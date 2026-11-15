<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O TechCrunch sai do feed geral para o da categoria de segurança (decisão do
 * dono, 2026-11-01): o geral trazia resenha de celular, IA e evento, e ~1/4 das
 * matérias do feed público vinham só dele, quase todas low.
 *
 * Só troca a linha que ainda tem o endereço geral, e só se o de segurança não
 * estiver cadastrado em outra (o feed_url é único). O nome acompanha se ainda
 * é o do seeder; ligada, intervalo e peso ficam como o admin deixou. O
 * `feed_changed_at` faz o corte do CrawlerService contar a partir de agora: o
 * arquivo do feed novo fica de fora, como numa fonte recém-cadastrada.
 */
return new class extends Migration
{
    private const GENERAL = 'https://techcrunch.com/feed/';

    private const SECURITY = 'https://techcrunch.com/category/security/feed/';

    public function up(): void
    {
        $source = DB::table('sources')->where('feed_url', self::GENERAL)->first();

        if (! $source || DB::table('sources')->where('feed_url', self::SECURITY)->exists()) {
            return;
        }

        DB::table('sources')->where('id', $source->id)->update([
            'feed_url' => self::SECURITY,
            'name' => $source->name === 'TechCrunch' ? 'TechCrunch — Security' : $source->name,
            'feed_changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('sources')->where('feed_url', self::GENERAL)->exists()) {
            return;
        }

        $source = DB::table('sources')->where('feed_url', self::SECURITY)->whereNotNull('feed_changed_at')->first();

        if ($source) {
            DB::table('sources')->where('id', $source->id)->update([
                'feed_url' => self::GENERAL,
                'name' => $source->name === 'TechCrunch — Security' ? 'TechCrunch' : $source->name,
                'feed_changed_at' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
