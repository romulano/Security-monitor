<?php

namespace Tests\Feature\Lookalike;

use App\Services\Lookalike\VariantGenerator;
use Tests\TestCase;

/** As variações que um golpista registraria. */
class VariantGeneratorTest extends TestCase
{
    public function test_generates_the_usual_tricks_for_a_com_br_domain(): void
    {
        $variants = (new VariantGenerator)->generate('acmepay.com.br', max: 5000);

        $this->assertSame('terminacao', $variants['acmepay.com'] ?? null);
        $this->assertSame('terminacao', $variants['acmepay.net'] ?? null);
        $this->assertSame('parecida', $variants['acrnepay.com.br'] ?? null, 'm → rn engana a olho nu');
        $this->assertSame('palavra', $variants['acmepay-login.com.br'] ?? null);
        $this->assertSame('palavra', $variants['acmepaypix.com'] ?? null);
        $this->assertSame('hifen', $variants['acme-pay.com.br'] ?? null);
        $this->assertSame('omissao', $variants['acmpay.com.br'] ?? null);
        $this->assertSame('troca', $variants['amcepay.com.br'] ?? null);
        $this->assertSame('repeticao', $variants['acmepayy.com.br'] ?? null);
        $this->assertArrayHasKey('acmepey.com.br', $variants);

        // "á" e "а" (cirílico) viram punycode; o .br não aceita cirílico.
        $this->assertSame('acento', $variants[idn_to_ascii('ácmepay.com.br', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)] ?? null);
        $this->assertSame('outro-alfabeto', $variants[idn_to_ascii('аcmepay.com', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)] ?? null);
        $this->assertArrayNotHasKey(idn_to_ascii('аcmepay.com.br', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46), $variants);
    }

    public function test_never_includes_the_client_domains_and_only_valid_names(): void
    {
        $variants = (new VariantGenerator)->generate('acmepay.com.br', ['acmepay.com'], ['acmepay.com'], max: 5000);

        $this->assertArrayNotHasKey('acmepay.com.br', $variants);
        $this->assertArrayNotHasKey('acmepay.com', $variants);

        foreach (array_keys($variants) as $host) {
            $this->assertMatchesRegularExpression('/^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host);
            $this->assertStringNotContainsString('--', str_replace('xn--', '', $host), $host);
        }
    }

    public function test_brand_names_become_labels_and_the_cap_keeps_the_most_deceptive(): void
    {
        $variants = (new VariantGenerator)->generate('fatalmodel.com', ['Fatal Model', 'fatalmodel.com'], max: 5000);
        $this->assertSame('marca', $variants['fatal-model.com'] ?? null);

        $few = (new VariantGenerator)->generate('fatalmodel.com', max: 40);
        $this->assertCount(40, $few);
        // Os primeiros são a mesma marca em outra terminação, não erros de digitação distantes.
        $this->assertSame('terminacao', $few['fatalmodel.com.br']);
        $this->assertNotContains('acrescimo', $few);
    }

    public function test_checks_100_suffixes_with_the_app_and_typos_in_all_of_them(): void
    {
        $this->assertCount(100, VariantGenerator::SUFFIXES);
        $this->assertSame(VariantGenerator::SUFFIXES, array_values(array_unique(VariantGenerator::SUFFIXES)));
        $this->assertSame(['com', 'com.br', 'net', 'net.br', 'org', 'org.br', 'app'], array_slice(VariantGenerator::SUFFIXES, 0, 7), 'as de antes continuam na frente, na mesma ordem');

        $variants = (new VariantGenerator)->generate('acmepay.com.br', max: 100000);

        $this->assertSame('terminacao', $variants['acmepay.app'] ?? null);
        $this->assertSame('terminacao', $variants['acmepay.uy'] ?? null);
        $this->assertSame('omissao', $variants['acmpay.shop'] ?? null, 'erro de digitação em uma das 30 de antes');
        $this->assertSame('omissao', $variants['acmpay.id'] ?? null, 'e na última da lista');
        $this->assertSame('parecida', $variants['acrnepay.xyz'] ?? null);
        $this->assertSame('vizinha', $variants['acmepsy.app'] ?? null);
        $this->assertSame('outro-alfabeto', $variants[idn_to_ascii('аcmepay.org', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)] ?? null);
        $this->assertArrayNotHasKey('acmepay.tk', $variants, 'sufixo fora da lista não é inventado');
        $this->assertArrayNotHasKey('acmepay-login.xyz', $variants, 'palavra de golpe continua só em .com e .com.br');
    }

    public function test_the_cap_cuts_the_least_likely_suffixes_last(): void
    {
        $generator = new VariantGenerator;
        $all = $generator->generate('acmepay.com.br', max: 100000);
        $position = array_flip(array_keys($all));

        // Tudo o que já existia vem antes do erro de digitação nas terminações novas.
        $this->assertLessThan($position['acmpay.id'], $position['acmpay.com.br']);
        $this->assertLessThan($position['acmpay.id'], $position['acmepay-login.com.br']);
        $this->assertLessThan($position['acmpay.shop'], $position['acmepaypix.com']);
        // E entre as novas, a mais relevante vem antes.
        $this->assertLessThan($position['acmpay.id'], $position['acmpay.shop']);

        $cut = $generator->generate('acmepay.com.br', max: 450);
        $this->assertSame(array_slice($all, 0, 450, true), $cut, 'o teto corta o fim da fila, sem reordenar');
        $this->assertArrayHasKey('acmepay-login.com.br', $cut);
        $this->assertArrayNotHasKey('acmpay.id', $cut);
    }
}
