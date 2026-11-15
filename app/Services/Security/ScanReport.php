<?php

namespace App\Services\Security;

/** O resultado de uma varredura: os resultados, a nota (100 menos os pontos perdidos) e o estado do site. */
final class ScanReport
{
    /** @param  list<CheckResult>  $results */
    public function __construct(
        public readonly array $results,
        public readonly string $status,
        public readonly int $score,
        public readonly ?int $httpStatus,
        public readonly ?TlsReport $tls,
        public readonly int $durationMs,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  list<CheckResult>  $results
     */
    public static function build(array $results, ?int $httpStatus, ?TlsReport $tls, int $durationMs, bool $reachable): self
    {
        if (! $reachable) {
            return new self($results, 'erro', 0, $httpStatus, $tls, $durationMs, 'o site não respondeu');
        }

        $lost = array_sum(array_map(fn (CheckResult $r) => CheckCatalog::penalty($r->id, $r->status), $results));
        $statuses = array_column($results, 'status');

        return new self(
            $results,
            in_array(CheckResult::CRITICAL, $statuses, true) ? 'critico' : (in_array(CheckResult::WARNING, $statuses, true) ? 'aviso' : 'ok'),
            max(0, 100 - $lost),
            $httpStatus,
            $tls,
            $durationMs,
        );
    }

    /** Só estado e valor curto de cada ocorrência, no formato que vai para o banco. @return array<string, array{s: string, v: string}> */
    public function toResults(): array
    {
        $out = [];

        foreach ($this->results as $result) {
            $out[$result->key()] = ['s' => $result->status, 'v' => $result->value];
        }

        return $out;
    }

    /** Muda quando muda o estado de qualquer verificação (o valor curto não conta: "vence em 40 dias" muda todo dia). */
    public function fingerprint(): string
    {
        $states = array_map(fn (CheckResult $r) => $r->key().'='.$r->status, $this->results);
        sort($states);

        return sha1(implode("\n", $states));
    }

    /** @return list<CheckResult> */
    public function problems(): array
    {
        return array_values(array_filter($this->results, fn (CheckResult $r) => $r->isProblem()));
    }
}
