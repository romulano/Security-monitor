<?php

namespace App\Services\Security\Checks;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;
use Illuminate\Support\Carbon;

/** /.well-known/security.txt (RFC 9116): para onde vai quem achar uma falha. Arquivo público; um GET. */
class SecurityTxtCheck implements SiteCheck
{
    public function ids(): array
    {
        return ['wk.security_txt'];
    }

    public function run(SiteProbe $probe): array
    {
        $response = $probe->fetch('/.well-known/security.txt', 8192, follow: true, accept: 'text/plain,*/*;q=0.5');

        if ($response['blocked'] || ! $probe->answered($response)) {
            return [CheckResult::untested('wk.security_txt', $response['blocked'] ? (string) $response['error'] : 'o site não respondeu')];
        }

        $body = (string) $response['body'];

        if ((int) $response['status'] !== 200 || ! preg_match('/^\s*Contact:\s*\S+/mi', $body)) {
            return [CheckResult::warning('wk.security_txt', 'ausente ou sem o campo Contact')];
        }

        if (! preg_match('/^\s*Expires:\s*(.+)$/mi', $body, $m)) {
            return [CheckResult::warning('wk.security_txt', 'sem o campo Expires')];
        }

        try {
            $expires = Carbon::parse(trim($m[1]));
        } catch (\Throwable) {
            return [CheckResult::warning('wk.security_txt', 'Expires em formato inválido')];
        }

        return [$expires->isPast()
            ? CheckResult::warning('wk.security_txt', 'Expires venceu em '.$expires->format('d/m/Y'))
            : CheckResult::ok('wk.security_txt', 'válido até '.$expires->format('d/m/Y'))];
    }
}
