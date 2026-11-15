<?php

namespace App\Services\Security\Checks;

use App\Services\Lookalike\RdapClient;
use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;
use App\Support\RegistrableDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Quando o registro do domínio vence (RDAP, só dado público do domínio; nada do titular). A consulta
 * é cacheada por 24 h: o vencimento não muda a cada varredura. O .br nem sempre informa a data.
 */
class DomainExpiryCheck implements SiteCheck
{
    public function __construct(private RdapClient $rdap) {}

    public function ids(): array
    {
        return ['dom.expiry'];
    }

    public function run(SiteProbe $probe): array
    {
        $domain = RegistrableDomain::of($probe->host());

        if (! $domain) {
            return [CheckResult::untested('dom.expiry', 'não há domínio registrável')];
        }

        $expires = Cache::remember("security.rdap.{$domain}", 86400, fn () => (string) ($this->rdap->domain($domain)['expires_at'] ?? ''));

        if ($expires === '') {
            return [CheckResult::untested('dom.expiry', 'o registro não informa o vencimento')];
        }

        try {
            $date = Carbon::parse($expires);
        } catch (\Throwable) {
            return [CheckResult::untested('dom.expiry', 'data de vencimento ilegível')];
        }

        $days = (int) floor(now()->diffInSeconds($date, false) / 86400);

        return [match (true) {
            $days < 0 => CheckResult::critical('dom.expiry', 'o domínio venceu há '.abs($days).' dia(s)'),
            $days <= 7 => CheckResult::critical('dom.expiry', "o domínio vence em {$days} dia(s)"),
            $days <= 30 => CheckResult::warning('dom.expiry', "o domínio vence em {$days} dias"),
            default => CheckResult::ok('dom.expiry', "vence em {$days} dias ({$date->timezone('America/Sao_Paulo')->format('d/m/Y')})"),
        }];
    }
}
