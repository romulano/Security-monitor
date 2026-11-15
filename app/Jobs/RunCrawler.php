<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\Crawler\CrawlerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Coleta disparada pelo admin.
 *
 * Vai para a fila em vez de rodar dentro da requisição: varrer 36 fontes leva
 * minutos, estoura o timeout do nginx e, quando o navegador reenvia o POST,
 * duas varreduras acabam coletando em dobro das mesmas fontes. O lock garante
 * que só uma coleta manual rode por vez.
 */
class RunCrawler implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public bool $force = false) {}

    public function handle(CrawlerService $crawler): void
    {
        $lock = Cache::lock('secmon:crawler', 3600);

        if (! $lock->get()) {
            Log::info('Coleta manual ignorada: já existe uma em andamento.');

            return;
        }

        try {
            $runs = $this->force
                ? Source::query()->where('enabled', true)->get()->map(fn (Source $s) => $crawler->runSource($s, 'manual'))
                : collect($crawler->runDue('manual'));

            Log::info('Coleta manual concluída', [
                'fontes' => count($runs),
                'novos' => collect($runs)->sum('items_new'),
            ]);
        } finally {
            $lock->release();
        }
    }
}
