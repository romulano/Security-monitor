<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;

/**
 * O servidor do cliente nos testes: confere uma entrega do webhook como a
 * documentação manda (os bytes do corpo, o `t` na janela de 5 minutos, o HMAC e
 * o Security-Monitor-Notification em tempo constante).
 */
class WebhookReceiver
{
    public static function accepts(Request $request, string $secret, ?int $now = null): bool
    {
        $parts = [];

        foreach (explode(',', $request->header('Security-Monitor-Signature')[0] ?? '') as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
            $parts[$key] = $value;
        }

        $t = $parts['t'] ?? '';

        return ctype_digit($t)
            && abs(($now ?? time()) - (int) $t) <= 300
            && hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), $secret), $parts['v1'] ?? '')
            && hash_equals($secret, $request->header('Security-Monitor-Notification')[0] ?? '');
    }

    /** As chaves do JSON, em árvore: lista vira as chaves do primeiro item; valor vira nulo. */
    public static function shape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            return $value === [] || ! is_array($value[0]) ? [] : [self::shape($value[0])];
        }

        $shape = [];

        foreach ($value as $key => $item) {
            $shape[$key] = self::shape($item);
        }

        ksort($shape);

        return $shape;
    }
}
