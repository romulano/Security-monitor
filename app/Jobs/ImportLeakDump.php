<?php

namespace App\Jobs;

use App\Models\LeakDump;
use App\Services\Leaks\LeakImporter;
use App\Services\Leaks\LeakScanner;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carrega um .txt da pasta de vazamentos para o banco e, se o admin mandou, apaga o arquivo.
 *
 * Fila própria (`vazamentos`, worker `fila-vazamentos`): um dump de gigabytes leva minutos, e numa
 * fila compartilhada ele seguraria a análise e os alertas atrás dele.
 *
 * Deploy pode matar o job no meio — é o padrão do projeto (`fila-chaves` nem espera). Aqui isso é
 * seguro porque a carga é idempotente (`insert ... on conflict do nothing`): o retry recomeça o
 * arquivo do zero, repete trabalho, mas não duplica linha. Por isso o `handle` aceita o dump em
 * `running`, e não só em `queued` — senão o retry desistiria calado.
 *
 * O arquivo só é apagado depois de a carga terminar, e nunca quando ela não leu linha nenhuma:
 * arquivo vazio ou ilegível é sinal de problema, e a pasta não tem backup.
 */
class ImportLeakDump implements ShouldQueue
{
    use Queueable;

    /** Deploy mata o job; o retry recomeça o arquivo, o que a idempotência torna seguro. */
    public int $tries = 3;

    /** Um dump de 2 GB leva dezenas de minutos. Acima do timeout do worker não passa. */
    public int $timeout = 5400;

    public function __construct(public int $dumpId)
    {
        $this->onQueue('vazamentos');
    }

    public function handle(LeakImporter $importer): void
    {
        $dump = LeakDump::find($this->dumpId);

        if ($dump === null || ! in_array($dump->status, ['queued', 'running'], true)) {
            return;
        }

        $dump->update(['status' => 'running', 'started_at' => $dump->started_at ?? now(), 'error' => null]);

        try {
            $tally = $importer->import($dump->source, $dump->path, LeakScanner::DISK);
        } catch (Throwable $e) {
            $dump->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 200),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $removed = false;

        // Apagar é escolha do admin e é irreversível. Arquivo que não rendeu linha alguma fica,
        // mesmo com a opção ligada: é mais provável ser erro de formato do que dump vazio de fato.
        if (Settings::bool('leaks.delete_after_import') && $tally['linhas'] > 0) {
            try {
                Storage::disk(LeakScanner::DISK)->delete($dump->path);
                $removed = true;
            } catch (Throwable $e) {
                // A carga deu certo: não é motivo para marcar o dump como falho. Fica registrado
                // que o arquivo continua na pasta, para o admin apagar à mão se quiser.
                $dump->update(['error' => 'carregado, mas não apaguei da pasta: '.mb_substr($e->getMessage(), 0, 160)]);
            }
        }

        $dump->update([
            'status' => 'done',
            'tally' => $tally,
            'removed' => $removed,
            'finished_at' => now(),
        ]);
    }

    /** Estourou as tentativas: o dump não fica preso em `running` para sempre. */
    public function failed(?Throwable $e): void
    {
        LeakDump::where('id', $this->dumpId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed',
            'error' => mb_substr($e?->getMessage() ?? 'o job falhou sem mensagem', 0, 200),
            'finished_at' => now(),
        ]);
    }
}
