<?php

namespace App\Services\Security;

use Carbon\CarbonImmutable;

/** O que o handshake TLS mostrou: confiança da cadeia, validade e protocolo. Sem o certificado inteiro. */
final class TlsReport
{
    public function __construct(
        public readonly bool $reachable,
        public readonly bool $trusted = false,
        public readonly ?string $error = null,
        public readonly ?CarbonImmutable $notAfter = null,
        public readonly ?string $subject = null,
        public readonly ?string $issuer = null,
        public readonly ?string $protocol = null,
        public readonly ?string $fingerprint = null,
        /** O host aponta para um endereço interno: nenhuma conexão foi aberta. */
        public readonly ?string $blocked = null,
    ) {}

    public static function unreachable(string $error): self
    {
        return new self(reachable: false, error: $error);
    }

    public static function blocked(string $reason): self
    {
        return new self(reachable: false, error: $reason, blocked: $reason);
    }
}
