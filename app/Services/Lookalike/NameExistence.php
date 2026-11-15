<?php

namespace App\Services\Lookalike;

use App\Services\Alerts\Discovery\DnsResolver;
use App\Support\RegistrableDomain;

/**
 * Quais nomes existem no DNS: registrável tem NS; subdomínio (dos certificados)
 * tem IP. Só DNS, nenhuma página é aberta.
 *
 * DNS que não respondeu (timeout, SERVFAIL) não é "não existe": o nome é
 * perguntado de novo (`dns_retry_passes` vezes, com uma pausa) e, se continuar
 * mudo, volta separado em `unanswered`, para quem chama decidir o que fazer.
 *
 * Um nome mudo custa ~30 s por pergunta (o PHP não tem tempo limite por
 * consulta), então a conferência tem um orçamento (`dns_batch_budget_seconds`):
 * passado dele, o que falta não é mais perguntado e volta como sem resposta.
 * Sem isso, 2 nomes mudos em 3 perguntas seguram um lote por quase 3 minutos.
 */
class NameExistence
{
    public function __construct(private DnsResolver $dns) {}

    /**
     * Um lote de candidatos: os que existem (host => técnica), os primeiros que
     * ninguém registrou (para o registro defensivo, na ordem recebida) e quantos
     * ficaram sem resposta.
     *
     * @param  list<array{0: string, 1: ?string}>  $candidates  [host, técnica]
     * @return array{found: array<string, ?string>, free: list<array{host: string, technique: ?string}>, unanswered: int, checked: int}
     */
    public function checkCandidates(array $candidates): array
    {
        $techniques = [];

        foreach ($candidates as [$host, $technique]) {
            $techniques[$host] = $technique;
        }

        $result = $this->check(array_keys($techniques));
        $free = [];

        foreach ($result['missing'] as $host) {
            if (count($free) >= (int) config('secmon.lookalikes.free_variants', 15)) {
                break;
            }

            $free[] = ['host' => $host, 'technique' => $techniques[$host]];
        }

        return [
            'found' => array_intersect_key($techniques, array_flip($result['found'])),
            'free' => $free,
            'unanswered' => count($result['unanswered']),
            'checked' => count($techniques),
        ];
    }

    /**
     * @param  list<string>  $hosts
     * @return array{found: list<string>, missing: list<string>, unanswered: list<string>} cada lista na ordem recebida
     */
    public function check(array $hosts): array
    {
        $state = array_fill_keys($hosts, null);
        $pending = array_keys($state);
        $deadline = microtime(true) + max(0, (float) config('secmon.lookalikes.dns_batch_budget_seconds', 90));

        for ($pass = 0, $passes = max(0, (int) config('secmon.lookalikes.dns_retry_passes', 2)); $pass <= $passes && $pending !== []; $pass++) {
            if ($pass > 0) {
                usleep(max(0, (int) config('secmon.lookalikes.dns_retry_pause_ms', 500)) * 1000);
            }

            $again = [];

            foreach ($pending as $host) {
                if (microtime(true) >= $deadline) {
                    $again[] = $host;

                    continue;
                }

                $answer = $this->ask($host);

                if ($answer === null) {
                    $again[] = $host;
                } else {
                    $state[$host] = $answer;
                }
            }

            $pending = $again;
        }

        $found = $missing = $unanswered = [];

        foreach ($state as $host => $exists) {
            match ($exists) {
                true => $found[] = $host,
                false => $missing[] = $host,
                null => $unanswered[] = $host,
            };
        }

        return ['found' => $found, 'missing' => $missing, 'unanswered' => $unanswered];
    }

    /** Existe? Nulo: o DNS não respondeu. */
    private function ask(string $host): ?bool
    {
        if (RegistrableDomain::of($host) === $host) {
            $ns = $this->dns->nsOrNull($host);

            return $ns === null ? null : $ns !== [];
        }

        $ips = $this->dns->ipsOrNull($host);

        return $ips === null ? null : $ips !== [];
    }
}
