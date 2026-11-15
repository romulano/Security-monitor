<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de proxy que o cURL aceita: `esquema://[usuario:senha@]host:porta`, com
 * esquema http, https, socks5 ou socks5h e porta obrigatória. A regra `url` do
 * Laravel não serve porque recusa o `socks5h`.
 */
class ProxyUrl implements ValidationRule
{
    public const SCHEMES = ['http', 'https', 'socks5', 'socks5h'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) && ! preg_match('/\s/', $value) ? parse_url($value) : false;

        $valid = is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), self::SCHEMES, true)
            && ($parts['host'] ?? '') !== ''
            && ($parts['port'] ?? 0) >= 1 && $parts['port'] <= 65535
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query']) && ! isset($parts['fragment']);

        if (! $valid) {
            $fail('Use esquema://usuario:senha@host:porta, com http, https, socks5 ou socks5h.');
        }
    }
}
