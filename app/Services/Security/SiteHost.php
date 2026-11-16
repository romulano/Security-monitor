<?php

namespace App\Services\Security;

/**
 * O endereço que o dono digita para monitorar: "https://App.Exemplo.com.br/login" vira
 * "app.exemplo.com.br". Instalação local, de um dono só: não há cliente de terceiro para
 * proteger daqui, então aceita qualquer host, inclusive IP (decimal, octal, hexadecimal ou
 * pontilhado), "localhost", TLD interno e nome de container sem ponto ("secmon-db",
 * "hermes") — quem escolhe o alvo é o próprio dono.
 *
 * Só sobra a sanitização que não é sobre o alvo: tira espaço em volta, esquema, caminho e
 * query; recusa caractere de controle e barra invertida; recusa `@` e `:` sobrando (ainda
 * não fazem sentido no campo "host" — userinfo e porta ficam fora); normaliza IDN; e exige
 * que o resultado pareça um host (não aceita string vazia nem lixo com espaço no meio).
 *
 * Ao contrário do DomainName, mantém o "www.": www.exemplo.com e exemplo.com são sites diferentes.
 */
final class SiteHost
{
    /** Rótulos separados por ponto, cada um alfanumérico (com hífen no meio): cobre nome DNS e qualquer forma de IP. */
    private const HOST = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

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

        return preg_match(self::HOST, $value) ? $value : null;
    }
}
