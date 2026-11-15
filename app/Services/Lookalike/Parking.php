<?php

namespace App\Services\Lookalike;

/**
 * Domínio estacionado, à venda ou com a página padrão do servidor: pelo DNS
 * (servidores das empresas de estacionamento) ou pela página (texto de
 * "à venda", scripts de anúncio, "Welcome to nginx").
 */
final class Parking
{
    /** Servidores de DNS de quem estaciona domínio para vender ou mostrar anúncio. */
    private const NS = [
        'sedoparking.com', 'bodis.com', 'parkingcrew.net', 'above.com', 'dan.com', 'afternic.com', 'hugedomains.com',
        'parklogic.com', 'cashparking.com', 'parked.com', 'ztomy.com', 'voodoo.com', 'domainparking.ru', 'undeveloped.com',
        'uniregistrymarket.link', 'smartname.com', 'namebrightdns.com', 'dsredirection.com', 'park.do', 'zone.eu.parking',
    ];

    private const HOSTS = [
        'sedo.com', 'sedoparking.com', 'bodis.com', 'parkingcrew.net', 'dan.com', 'afternic.com', 'hugedomains.com', 'above.com',
        'parklogic.com', 'domainmarket.com', 'atom.com', 'squadhelp.com', 'undeveloped.com', 'brandbucket.com', 'godaddy.com',
    ];

    private const PHRASES = [
        'domain is for sale', 'this domain may be for sale', 'buy this domain', 'domain for sale', 'make an offer', 'is parked free',
        'this domain is parked', 'parked domain', 'domain parking', 'related searches', 'este dominio esta a venda', 'dominio a venda',
        'compre este dominio', 'este dominio pode estar a venda', 'faca uma oferta', 'dominio registrado', 'em construcao', 'coming soon',
        'under construction', 'website coming soon', 'site em construcao', 'default web page', 'welcome to nginx',
        'apache2 default page', 'it works!', 'index of /', 'account suspended', 'conta suspensa', 'a brand new domain',
        'not linked to any directory', 'bitnami: open source. simplified', 'future home of', 'site is under construction',
    ];

    /** @param  list<string>  $ns */
    public static function byNameservers(array $ns): bool
    {
        foreach ($ns as $server) {
            foreach (self::NS as $parking) {
                if ($server === $parking || str_ends_with($server, '.'.$parking)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<string>  $resourceHosts */
    public static function looksParked(?string $title, ?string $text, array $resourceHosts, ?string $finalUrl): bool
    {
        $final = strtolower((string) parse_url((string) $finalUrl, PHP_URL_HOST));

        // "/lander" é a página de estacionamento padrão (Bodis, ParkingCrew).
        if (rtrim((string) parse_url((string) $finalUrl, PHP_URL_PATH), '/') === '/lander') {
            return true;
        }

        foreach (self::HOSTS as $host) {
            if ($final === $host || str_ends_with($final, '.'.$host)) {
                return true;
            }
        }

        foreach ($resourceHosts as $resource) {
            foreach (['sedoparking.com', 'bodis.com', 'parkingcrew.net', 'parklogic.com', 'above.com'] as $host) {
                if ($resource === $host || str_ends_with($resource, '.'.$host)) {
                    return true;
                }
            }
        }

        $plain = BrandContext::normalize(($title ?? '').' '.mb_substr((string) $text, 0, 1500));

        foreach (self::PHRASES as $phrase) {
            if (str_contains($plain, BrandContext::normalize($phrase))) {
                return true;
            }
        }

        return false;
    }
}
