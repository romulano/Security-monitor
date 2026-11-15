<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O TechCrunch volta ao feed geral (decisão do dono, 2026-11-01, à noite): o
 * Security Monitor mostra tudo ao usuário. Desfaz a 2026_11_21_000200 onde ela ainda vale;
 * em produção a fonte já foi trocada à mão, e aqui nada muda.
 *
 * Só troca a linha que ainda tem o endereço de segurança, e só se o geral não
 * estiver cadastrado em outra (o feed_url é único). O nome acompanha se ainda é
 * o daquela troca. O `feed_changed_at` recomeça o corte do CrawlerService: o que
 * o geral já trouxe antes é reconhecido pela URL e não volta duplicado.
 */
return new class extends Migration
{
    private const GENERAL = 'https://techcrunch.com/feed/';

    private const SECURITY = 'https://techcrunch.com/category/security/feed/';

    public function up(): void
    {
        $source = DB::table('sources')->where('feed_url', self::SECURITY)->first();

        if (! $source || DB::table('sources')->where('feed_url', self::GENERAL)->exists()) {
            return;
        }

        DB::table('sources')->where('id', $source->id)->update([
            'feed_url' => self::GENERAL,
            'name' => $source->name === 'TechCrunch — Security' ? 'TechCrunch' : $source->name,
            'feed_changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Voltar ao feed de segurança seria refazer a decisão desfeita: fica como está.
    }
};
