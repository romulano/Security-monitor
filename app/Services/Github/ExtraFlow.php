<?php

namespace App\Services\Github;

use App\Jobs\InterpretGithubExtra;
use App\Models\GithubMonitor;
use App\Services\Alerts\FeedbackNote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O texto "Adicional" da entrevista do GitHub, do formulário ao que a IA
 * entendeu. Só vai para a IA quando há texto, quando ele mudou e quando não
 * tem cara de instrução para ela; no máximo `extra_daily_limit` leituras por
 * cliente por dia (o texto continua salvo, e a leitura fica para a varredura).
 */
class ExtraFlow
{
    public function __construct(private ExtraInterpreter $interpreter) {}

    /** O texto mudou no formulário: guarda e, se for o caso, manda para a IA. */
    public function changed(GithubMonitor $monitor, ?string $text): void
    {
        $text = self::clean($text);
        $hash = $text === null ? null : hash('sha256', $text);

        if ($hash === $monitor->extra_hash) {
            return;
        }

        $monitor->forceFill([
            'extra_text' => $text,
            'extra_hash' => $hash,
            'extra_result' => [],
            'extra_error' => null,
            'extra_at' => null,
            'extra_status' => match (true) {
                $text === null => null,
                FeedbackNote::looksLikeInjection($text) => 'flagged',
                ! ExtraInterpreter::enabled() => 'off',
                default => 'pending',
            },
        ])->save();

        if ($monitor->extra_status === 'flagged') {
            Log::notice('Texto adicional do GitHub com cara de instrução para a IA; não vai para o modelo', ['monitor' => $monitor->id]);
        }

        if ($monitor->extra_status === 'pending') {
            InterpretGithubExtra::dispatch($monitor->id);
        }
    }

    /** A leitura pela IA (pelo job, ou pela varredura se ficou pendente). */
    public function interpret(GithubMonitor $monitor): void
    {
        if (! ExtraInterpreter::enabled() || blank($monitor->extra_text)) {
            $monitor->forceFill(['extra_status' => blank($monitor->extra_text) ? null : 'off'])->save();

            return;
        }

        $key = 'github-extra:'.$monitor->id.':'.now()->toDateString();

        if (Cache::get($key, 0) >= (int) config('secmon.github.extra_daily_limit', 5)) {
            $monitor->forceFill(['extra_status' => 'pending', 'extra_error' => 'limite de leituras de hoje; a próxima varredura lê o texto'])->save();

            return;
        }

        Cache::put($key, Cache::get($key, 0) + 1, now()->endOfDay());

        try {
            $result = retry(2, fn () => $this->interpreter->interpret($monitor));
            $monitor->forceFill(['extra_status' => 'done', 'extra_result' => $result, 'extra_error' => null, 'extra_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('Leitura do texto adicional do GitHub falhou', ['monitor' => $monitor->id, 'error' => $e->getMessage()]);
            $monitor->forceFill(['extra_status' => 'failed', 'extra_error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }
    }

    /** Uma linha de texto, sem caracteres de controle, até o limite (o formulário já recusa o que passa). */
    public static function clean(?string $text): ?string
    {
        $text = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/[^\P{C}\n]+/u', ' ', (string) $text)));

        return $text === '' ? null : mb_substr($text, 0, (int) config('secmon.github.extra_max', 1000));
    }
}
