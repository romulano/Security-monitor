<?php

namespace Tests\Unit;

use App\Services\Github\SecretMasker;
use PHPUnit\Framework\TestCase;

/** O valor de uma chave nunca sai do mascarador: nem pelo nome do cliente, nem por nome genérico, nem sem nome. */
class SecretMaskerTest extends TestCase
{
    private const VALUES = [
        'Zq8xR2mLp0Vb7Kd9TwYc4Nf1Hs6Tq',
        'kR9vT2xQ8mN4pL7wE1sD6fH3jK0bV5cX9zA2yU8iO4o=',
        'S3nh4F0rte!',
        'sk_test_51HxYzAbCdEfGhIjKlMnOp',
        '9f8a7b6c5d4e3f2a1b0c9d8e',
        '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        'MIIEowIBAAKCAQEA1x2y3z',
    ];

    public function test_no_secret_value_survives(): void
    {
        $text = implode("\n", [
            'ACME_SITE_BR=Zq8xR2mLp0Vb7Kd9TwYc4Nf1Hs6Tq',
            'APP_KEY=base64:kR9vT2xQ8mN4pL7wE1sD6fH3jK0bV5cX9zA2yU8iO4o=',
            'const url = "postgres://app:S3nh4F0rte!@db.interno.acme.com:5432/x";',
            '"stripe": ["sk_test_51HxYzAbCdEfGhIjKlMnOp"]',
            "curl -H 'Authorization: Bearer acmk_live_9f8a7b6c5d4e3f2a1b0c9d8e'",
            'const digest = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08";',
            '-----BEGIN RSA PRIVATE KEY-----', 'MIIEowIBAAKCAQEA1x2y3z', '-----END RSA PRIVATE KEY-----',
        ]);

        $result = SecretMasker::mask($text, ['ACME_SITE_BR'], ['acmk_live_']);

        foreach (self::VALUES as $value) {
            $this->assertStringNotContainsString($value, $result['text']);
            $this->assertStringNotContainsString($value, json_encode($result['secrets']));
        }

        $this->assertStringContainsString('ACME_SITE_BR=Zq8x••••••••', $result['text']);
        $this->assertStringContainsString('acmk_live_••••••••', $result['text']);
        $this->assertTrue(collect($result['secrets'])->firstWhere('name', 'ACME_SITE_BR')['looks_real']);
    }

    public function test_reference_and_placeholder_are_told_apart_from_a_real_value(): void
    {
        $secrets = collect(SecretMasker::mask("API_KEY=\${process.env.KEY}\nDB_PASSWORD=changeme\nkey = AKIAIOSFODNN7EXAMPLE")['secrets'])->keyBy('name');

        $this->assertSame('reference', $secrets['API_KEY']['kind']);
        $this->assertSame('placeholder', $secrets['DB_PASSWORD']['kind']);
        $this->assertFalse($secrets['token AWS']['looks_real'], 'a chave de exemplo da AWS tem EXAMPLE no nome');
    }

    public function test_interview_tells_a_key_name_from_a_key_value(): void
    {
        foreach (['FM_SITE_BR', 'fm_site_br', 'acme.payments.api-key', 'fatalmodel-api-production-key'] as $name) {
            $this->assertFalse(SecretMasker::looksLikeSecretValue($name), $name);
        }

        foreach (['sk_test_51HxYzAbCdEfGhIjKlMnOp', 'Zq8xR2mLp0Vb7Kd9TwYc4Nf1', 'API_KEY=abc123xyz', 'ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789'] as $value) {
            $this->assertTrue(SecretMasker::looksLikeSecretValue($value), $value);
        }
    }
}
