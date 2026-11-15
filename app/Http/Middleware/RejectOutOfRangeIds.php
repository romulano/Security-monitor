<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Id da URL grande demais para o bigint (30 dígitos): o binding mandava o número ao Postgres,
 * que recusa com 22003 (numeric value out of range), e toda rota com binding numérico dava 500. Aqui é 404, como o id
 * que não existe, antes de qualquer consulta. Nos grupos `web` e `api`, logo antes do SubstituteBindings (a ordem
 * está no bootstrap/app.php); vale para todo parâmetro de rota só de dígitos, com binding ou não.
 */
class RejectOutOfRangeIds
{
    private const BIGINT_MAX = '9223372036854775807';

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            abort_if(is_string($value) && self::outOfRange($value), 404);
        }

        return $next($request);
    }

    public static function outOfRange(string $value): bool
    {
        if (preg_match('/^[+-]?0*(\d+)$/', $value, $match) !== 1) {
            return false;
        }

        $digits = $match[1];
        $max = strlen(self::BIGINT_MAX);

        return strlen($digits) > $max || (strlen($digits) === $max && strcmp($digits, self::BIGINT_MAX) > 0);
    }
}
