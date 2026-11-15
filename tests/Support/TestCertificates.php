<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Certificados de mentira para os testes do vínculo (A7), feitos na hora com chaves EC
 * (P-256, rápidas): uma CA, intermediárias e folhas com os SANs que o teste pedir. Nada
 * fica no repositório, nem chave. A validade começa agora, pelo relógio de verdade, que
 * é o do OpenSSL (o `openssl_x509_checkpurpose` não usa o relógio do Carbon).
 */
final class TestCertificates
{
    /** @var array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string} */
    public readonly array $ca;

    private int $serial = 1;

    public function __construct(string $name = 'Security Monitor Teste CA')
    {
        $this->ca = $this->issue(['CN' => $name], [], null, ca: true);
    }

    /** O pacote de CAs dos testes num arquivo (o `secmon.pentest.binding.ca_bundle`). */
    public function bundle(string $path): string
    {
        file_put_contents($path, $this->ca['pem']);

        return $path;
    }

    /** Uma intermediária, assinada pela CA (ou por outra intermediária). */
    public function intermediate(string $name, ?array $issuer = null): array
    {
        return $this->issue(['CN' => $name], [], $issuer ?? $this->ca, ca: true);
    }

    /**
     * Uma folha com os SANs dados, assinada pela CA ou pela intermediária: o PEM.
     *
     * @param  list<string>  $sans
     */
    public function leaf(array $sans, ?array $issuer = null, int $days = 30, string $usage = 'serverAuth', ?string $cn = null): string
    {
        return $this->issue(['CN' => $cn ?? ($sans[0] ?? 'sem-nome')], $sans, $issuer ?? $this->ca, days: $days, usage: $usage)['pem'];
    }

    /** Uma folha assinada por ela mesma (não chega a nenhuma CA). */
    public function selfSigned(array $sans): string
    {
        return $this->issue(['CN' => $sans[0] ?? 'sem-nome'], $sans, null)['pem'];
    }

    /**
     * @param  array<string, string>  $subject
     * @param  list<string>  $sans
     * @param  array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}|null  $issuer  nulo: assinada por ela mesma
     * @return array{cert: OpenSSLCertificate, key: OpenSSLAsymmetricKey, pem: string}
     */
    private function issue(array $subject, array $sans, ?array $issuer, bool $ca = false, int $days = 30, string $usage = 'serverAuth'): array
    {
        $config = (string) tempnam(sys_get_temp_dir(), 'secmon-cnf-');
        $extensions = $ca
            ? "basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
            : "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage={$usage}\n"
                .($sans === [] ? '' : 'subjectAltName='.implode(',', array_map(fn (string $san) => "DNS:{$san}", $sans))."\n");
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\n{$extensions}");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

        try {
            $key = openssl_pkey_new($options);
            $csr = $key === false ? false : openssl_csr_new($subject, $key, $options);
            $cert = $csr === false ? false : openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, $days, $options, $this->serial++);

            if ($key === false || $cert === false || ! openssl_x509_export($cert, $pem)) {
                throw new RuntimeException('Certificado de teste: '.openssl_error_string());
            }
        } finally {
            @unlink($config);
        }

        return ['cert' => $cert, 'key' => $key, 'pem' => $pem];
    }
}
