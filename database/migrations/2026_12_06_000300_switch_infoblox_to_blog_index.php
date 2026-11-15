<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Infoblox levou o blog para o Adobe Edge Delivery e o /blog/feed/ passou a
 * dar 404: a fonte só gerava erro a cada coleta. Os posts agora vêm do índice
 * JSON do próprio blog, filtrado pela seção do Threat Intel (o FeedReader lê
 * o que vem depois do #).
 *
 * Só troca a linha que ainda tem o feed velho, e só se o novo não estiver
 * cadastrado em outra (o feed_url é único). O `feed_changed_at` recomeça o
 * corte do CrawlerService: o índice traz o blog inteiro desde 2009, e nada
 * dele vira matéria de agora; o que o feed velho já trouxe também fica de fora.
 */
return new class extends Migration
{
    private const OLD = 'https://www.infoblox.com/blog/feed/';

    private const NEW = 'https://www.infoblox.com/blog-index.json#/blog/threat-intelligence/';

    public function up(): void
    {
        $source = DB::table('sources')->where('feed_url', self::OLD)->first();

        if (! $source || DB::table('sources')->where('feed_url', self::NEW)->exists()) {
            return;
        }

        DB::table('sources')->where('id', $source->id)->update([
            'feed_url' => self::NEW,
            'feed_changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('sources')->where('feed_url', self::OLD)->exists()) {
            return;
        }

        DB::table('sources')->where('feed_url', self::NEW)->whereNotNull('feed_changed_at')->update([
            'feed_url' => self::OLD,
            'feed_changed_at' => null,
            'updated_at' => now(),
        ]);
    }
};
