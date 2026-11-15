<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeWatch;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Support\DomainName;
use App\Support\RegistrableDomain;

/**
 * O que o Security Monitor sabe do cliente para julgar um domínio parecido: os domínios
 * dele (que nunca são visitados), os nomes da marca e o DNS do domínio
 * verificado. Só DNS: nenhuma requisição ao site.
 */
final class BrandContext
{
    /**
     * @param  list<string>  $ownDomains  registráveis do cliente
     * @param  list<string>  $brands  nomes da marca, em minúsculas e sem acento
     * @param  list<string>  $ns
     * @param  list<string>  $ips
     */
    public function __construct(
        public readonly string $domain,
        public readonly array $ownDomains,
        public readonly array $brands,
        public readonly array $ns = [],
        public readonly array $ips = [],
    ) {}

    public static function for(LookalikeWatch $watch, ?DnsResolver $dns = null): self
    {
        $domain = $watch->domain;
        $client = RegistrableDomain::of($domain->domain) ?? $domain->domain;
        $own = [$client];
        $brands = [VariantGenerator::split($client)[0]];

        foreach (array_merge($watch->brandNames(), [$watch->company_name]) as $brand) {
            $brand = trim((string) $brand);

            if ($brand === '') {
                continue;
            }

            // "fatalmodel.com" na lista de marcas é domínio do cliente, não só nome.
            if (str_contains($brand, '.') && ($asDomain = DomainName::normalize($brand))) {
                $own[] = RegistrableDomain::of($asDomain) ?? $asDomain;

                continue;
            }

            $brands[] = self::normalize($brand);
        }

        $brands = array_values(array_unique(array_filter($brands, fn ($b) => mb_strlen(str_replace(' ', '', $b)) >= 4)));

        return new self(
            $client,
            array_values(array_unique($own)),
            $brands,
            $dns ? $dns->ns($client) : [],
            $dns ? array_values(array_unique(array_merge($dns->ips($client), $dns->ips('www.'.$client)))) : [],
        );
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** O host é um dos domínios do cliente (ou subdomínio deles)? */
    public function isOwn(?string $host): bool
    {
        $host = strtolower(trim((string) $host, '.'));

        foreach ($this->ownDomains as $own) {
            if ($host === $own || str_ends_with($host, '.'.$own)) {
                return true;
            }
        }

        return false;
    }

    /** A marca aparece no texto? "Fatal Model" casa com "fatalmodel" e com "fatal model". */
    public function mentions(?string $text): bool
    {
        $plain = ' '.self::normalize((string) $text).' ';
        $joined = str_replace(' ', '', $plain);

        foreach ($this->brands as $brand) {
            if (str_contains($plain, ' '.$brand.' ') || (! str_contains($brand, ' ') && str_contains($joined, $brand) && strlen($brand) >= 6)) {
                return true;
            }

            if (str_contains($brand, ' ') && str_contains($joined, str_replace(' ', '', $brand))) {
                return true;
            }
        }

        return false;
    }
}
