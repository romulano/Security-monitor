<?php

namespace App\Support;

/**
 * Normaliza e valida o domínio que o cliente digita. Aceita "https://www.acme.com.br/x"
 * e guarda "acme.com.br". Recusa IP, porta, localhost e TLDs internos: o
 * domínio vai receber requests da descoberta, então não pode apontar para dentro.
 */
final class DomainName
{
    private const RESERVED_TLDS = ['localhost', 'local', 'internal', 'lan', 'home', 'corp', 'test', 'invalid', 'intranet', 'private'];

    public static function normalize(?string $input): ?string
    {
        $value = mb_strtolower(trim((string) $input));

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', $value);
        $value = rtrim($value, '.');

        if (str_contains($value, '@') || str_contains($value, ':')) {
            return null; // usuário:senha@ ou porta
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $value)) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }

        $value = preg_replace('/^www\./', '', $value);

        return self::isValid($value) ? $value : null;
    }

    public static function isValid(string $domain): bool
    {
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (! preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $domain)) {
            return false;
        }

        $tld = substr(strrchr($domain, '.'), 1);

        return ! in_array($tld, self::RESERVED_TLDS, true);
    }
}
