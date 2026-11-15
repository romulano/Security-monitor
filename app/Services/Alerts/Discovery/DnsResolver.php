<?php

namespace App\Services\Alerts\Discovery;

/** DNS atrás de uma interface: os testes não dependem da rede. */
interface DnsResolver
{
    /** @return list<string> */
    public function txt(string $host): array;

    /**
     * Como txt(), mas nulo quando o DNS não respondeu: "o TXT sumiu" é diferente
     * de "não sei". A reconferência da posse da stack de testes depende disso.
     *
     * @return list<string>|null
     */
    public function txtOrNull(string $host): ?array;

    /**
     * Como txtOrNull(), mas só os registros cujo dono é o próprio nome perguntado.
     * Num apelido (CNAME), o DNS segue o apelido e devolve o TXT do destino, que é
     * de outra zona: quem controla o destino provaria a posse do apelido. Toda
     * prova de posse usa este, e um nome com CNAME nunca prova a posse.
     *
     * @return list<string>|null
     */
    public function txtOwnedOrNull(string $host): ?array;

    /**
     * O DNS reverso (PTR) de um IP: os nomes, lista vazia quando o IP não tem
     * nome e nulo quando o DNS não respondeu.
     *
     * @return list<string>|null
     */
    public function ptrOrNull(string $ip): ?array;

    /** @return list<string> hosts dos MX, do mais preferido ao menos */
    public function mx(string $host): array;

    /** @return list<string> */
    public function ns(string $host): array;

    /** @return list<string> endereços A e AAAA */
    public function ips(string $host): array;

    /**
     * Como ns(), mas nulo quando o DNS não respondeu (timeout, SERVFAIL): "não sei" é diferente de "não existe".
     *
     * @return list<string>|null
     */
    public function nsOrNull(string $host): ?array;

    /**
     * Como ips(), mas nulo quando o DNS não respondeu e nada foi achado.
     *
     * @return list<string>|null
     */
    public function ipsOrNull(string $host): ?array;

    /** @return list<string> alvos do CNAME (vazio se o host não é um apelido) */
    public function cname(string $host): array;

    /**
     * Como cname(), mas nulo quando o DNS não respondeu: a cadeia de um nome do recon
     * da stack de testes não pode tomar "não sei" por "não é apelido".
     *
     * @return list<string>|null
     */
    public function cnameOrNull(string $host): ?array;
}
