<?php

namespace App\Services\Hermes;

use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * O hermes recusou por limite de uso da conta da IA ("You've hit your session
 * limit · resets 5am (UTC)"). Não adianta tentar de novo antes da hora que ele
 * dá: quem pode esperar (a fusão de matérias) volta para a fila até lá.
 */
class HermesLimitReached extends RuntimeException
{
    /** Sem hora legível na mensagem, tenta de novo daqui a uma hora. */
    private const FALLBACK_MINUTES = 60;

    public function __construct(string $message, public readonly Carbon $resetsAt)
    {
        parent::__construct($message);
    }

    /** O erro do hermes é de limite de uso? Então devolve a exceção, com a hora em que o limite volta. */
    public static function from(int $status, string $message, ?Carbon $now = null): ?self
    {
        if (! preg_match('/\b(?:hit|reached) your [a-z ]*limit\b|\busage limit\b/i', $message)) {
            return null;
        }

        return new self('hermes HTTP '.$status.': '.$message, self::resetTime($message, $now ?? Carbon::now('UTC')));
    }

    /** "resets 5am (UTC)", "resets 5:30pm (UTC)" ou "resets Nov 3, 5am (UTC)": a próxima vez que esse horário chega. */
    private static function resetTime(string $message, Carbon $now): Carbon
    {
        $now = $now->copy()->utc();

        if (! preg_match('/resets\s+(?:([A-Z][a-z]{2,8}\s+\d{1,2}),?\s+)?(\d{1,2})(?::(\d{2}))?\s*([ap]m)\b/i', $message, $m)) {
            return $now->addMinutes(self::FALLBACK_MINUTES);
        }

        $hour = (int) $m[2] % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);

        try {
            $at = ($m[1] !== '' ? Carbon::parse($m[1].' '.$now->year, 'UTC') : $now->copy()->startOfDay())
                ->setTime($hour, (int) ($m[3] ?? 0));
        } catch (Throwable) {
            return $now->addMinutes(self::FALLBACK_MINUTES);
        }

        // Sem data, o horário que já passou hoje é o de amanhã. Com data já passada, a
        // conta virou e a mensagem envelheceu: tenta de novo daqui a pouco.
        if ($at->lessThanOrEqualTo($now)) {
            return $m[1] === '' ? $at->addDay() : $now->addMinutes(self::FALLBACK_MINUTES);
        }

        return $at;
    }
}
