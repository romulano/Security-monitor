<?php

namespace App\Support;

/**
 * O que o site lê do navegador de quem chega: se é robô declarado e, se for,
 * de quem é e para que lê o site (RobotCatalog).
 */
final class UserAgents
{
    /** Robôs que se identificam (inclusive o que o Google manda conferir a página do anúncio). */
    public const BOTS = '/bot|crawl|spider|slurp|preview|mediapartners|headless|lighthouse|google-inspectiontool|facebookexternalhit|curl|wget|python-requests|go-http-client/i';

    public static function isBot(?string $userAgent): bool
    {
        return self::robot($userAgent) !== null;
    }

    /**
     * O robô declarado: o do catálogo, com dono e tipo, ou um sem dono
     * (navegador vazio, ou um nome de robô fora do catálogo). Nulo: gente.
     *
     * @return array{owner: ?string, kind: string, name: ?string}|null
     */
    public static function robot(?string $userAgent): ?array
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent !== '' && ($known = RobotCatalog::find($userAgent))) {
            return $known;
        }

        return $userAgent === '' || preg_match(self::BOTS, $userAgent) === 1
            ? ['owner' => null, 'kind' => RobotCatalog::UNKNOWN, 'name' => null]
            : null;
    }
}
