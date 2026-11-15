<?php

namespace App\Services\Security;

use App\Support\DomainName;

/**
 * O endereço que o admin digita para monitorar: "https://App.Exemplo.com.br/login" vira
 * "app.exemplo.com.br". É a primeira barreira contra SSRF (o servidor vai fazer as requisições):
 * só nome DNS com ponto. Recusa IP (inclusive 0x7f000001, decimal e octal), porta, usuário:senha,
 * localhost, TLD interno e nome de container sem ponto (es, secmon-db, hermes).
 *
 * Ao contrário do DomainName, mantém o "www.": www.exemplo.com e exemplo.com são sites diferentes.
 */
final class SiteHost
{
    public static function normalize(?string $input): ?string
    {
        $value = mb_strtolower(trim((string) $input));

        if ($value === '' || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $value)) {
            return null;
        }

        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', $value);
        $value = rtrim($value, '.');

        if ($value === '' || str_contains($value, '@') || str_contains($value, ':')) {
            return null;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $value)) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }

        return DomainName::isValid($value) ? $value : null;
    }
}
