<?php

namespace Tests\Unit;

use App\Models\NewsCase;
use App\Services\Exploits\MainCves;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Corpus da regra dos CVEs principais: cada matéria com o que a regra deve
 * devolver. A da Ivanti é a de produção (02/11), resumida nos detalhes.
 */
class MainCvesTest extends TestCase
{
    public static function ivanti(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../fixtures/exploits/ivanti-ics-2026.json'), true);
    }

    /** Os atributos da matéria (o modelo só nasce no teste, com a aplicação de pé). */
    private static function story(string $title, string $summary, array $cves, array $products = [], array $extra = []): array
    {
        return $extra + [
            'title' => $title,
            'summary' => $summary,
            'cves' => $cves,
            'indicators' => [
                ['field' => 'cves', 'label' => 'CVEs', 'values' => $cves],
                ['field' => 'affected_products', 'label' => 'Produtos', 'values' => $products],
            ],
            'first_published_at' => '2026-10-13 09:00:00',
        ];
    }

    public static function corpus(): array
    {
        $ivanti = self::ivanti();
        $explained = ['vulnerability_applies' => true, 'vulnerability_explanation' => $ivanti['vulnerability_explanation']];
        $chain = ['CVE-2026-111357', 'CVE-2026-118527', 'CVE-2026-104935'];

        return [
            // A cadeia nova; a CVE-2025-0282 (2025) e a do Windows não são o assunto.
            'Ivanti com a explicação da falha' => [self::story($ivanti['title'], $ivanti['summary'], $ivanti['cves'], $ivanti['affected_products'], $explained), $chain],
            // Matéria antiga, antes da explicação: a abertura cita a do Windows ("together with the Windows ... flaw").
            'Ivanti sem a explicação' => [self::story($ivanti['title'], $ivanti['summary'], $ivanti['cves'], $ivanti['affected_products']), $chain],
            // A abertura cita a falha antiga sem palavra de histórico; a explicação desempata.
            'Explicação vale mais que a abertura' => [self::story(
                'Ivanti Connect Secure zero-day exploited by China-nexus actor',
                'UNC5221, the group behind the CVE-2025-0282 attacks, is exploiting CVE-2026-6000 in Connect Secure.',
                ['CVE-2026-6000', 'CVE-2025-0282'], ['Ivanti Connect Secure'],
                ['vulnerability_applies' => true, 'vulnerability_explanation' => 'CVE-2026-6000 is a stack overflow in the Connect Secure web server.'],
            ), ['CVE-2026-6000']],
            'CVE no título' => [self::story('Fortinet patches FortiWeb flaw CVE-2026-24858 exploited in the wild', 'Fortinet fixed an authentication bypass.', ['CVE-2026-24858']), ['CVE-2026-24858']],
            'CVE só na abertura; a do ano passado nos detalhes' => [self::story(
                'Critical Cisco ISE flaw lets attackers run code as root',
                "**Cisco** fixed **CVE-2026-20001** in Identity Services Engine.\n\n### Technical Details\n\nIt is the second ISE root RCE after CVE-2025-20281 last year.",
                ['CVE-2026-20001', 'CVE-2025-20281'], ['Cisco ISE'],
            ), ['CVE-2026-20001']],
            'CVE antiga que é a notícia' => [self::story(
                'CISA adds 2019 Citrix ADC flaw to KEV after new attacks',
                'CISA added CVE-2019-19781, the Citrix ADC path traversal fixed in January 2020, to its KEV catalog.',
                ['CVE-2019-19781'], ['Citrix ADC'],
            ), ['CVE-2019-19781']],
            'Histórico na abertura' => [self::story(
                'Citrix NetScaler memory leak exploited in the wild',
                'Citrix fixed CVE-2026-3000 in NetScaler ADC. The bug is similar to CitrixBleed (CVE-2023-4966), exploited at scale.',
                ['CVE-2026-3000', 'CVE-2023-4966'], ['Citrix NetScaler ADC'],
            ), ['CVE-2026-3000']],
            'Histórico pela data' => [self::story(
                'Ivanti Connect Secure flaw exploited again',
                'Ivanti fixed CVE-2026-5000 in Connect Secure. In January 2025, CVE-2025-0282 hit the same appliance.',
                ['CVE-2026-5000', 'CVE-2025-0282'], ['Ivanti Connect Secure'],
            ), ['CVE-2026-5000']],
            'Dois fornecedores no título' => [self::story(
                'CISA adds Fortinet and Microsoft flaws to KEV',
                'CISA added the FortiOS flaw CVE-2026-1001 and the Windows flaw CVE-2026-1002 to KEV.',
                ['CVE-2026-1001', 'CVE-2026-1002'], ['Fortinet FortiOS', 'Microsoft Windows'],
            ), ['CVE-2026-1001', 'CVE-2026-1002']],
            'Resumo do Patch Tuesday' => [self::story(
                'Microsoft Patch Tuesday fixes three zero-days',
                'Microsoft fixed CVE-2026-2001, CVE-2026-2002 and CVE-2026-2003, all exploited.',
                ['CVE-2026-2001', 'CVE-2026-2002', 'CVE-2026-2003'], ['Microsoft Windows'],
            ), ['CVE-2026-2001', 'CVE-2026-2002', 'CVE-2026-2003']],
            'Campanha que usa CVE antiga como porta' => [self::story(
                'Akira hits hospitals through unpatched SonicWall VPNs',
                'The Akira group is exploiting **CVE-2024-40766** in SonicWall SonicOS to get in.',
                ['CVE-2024-40766'], ['SonicWall SonicOS'],
            ), ['CVE-2024-40766']],
            'Nenhum texto cita os CVEs' => [self::story('FortiWeb flaw exploited', 'Short summary.', ['CVE-2025-25257', 'CVE-2025-99999']), ['CVE-2025-25257', 'CVE-2025-99999']],
            'Só nos detalhes' => [self::story(
                'Apache Tomcat flaw exploited',
                "Attackers exploit a Tomcat flaw.\n\n### Technical Details\n\nThe flaw is CVE-2026-7000.",
                ['CVE-2026-7000'], ['Apache Tomcat'],
            ), ['CVE-2026-7000']],
            // Depois do prompt novo: a do Windows vem em `related_cves`, mas a lista antiga continua em `cves`.
            'Ivanti atualizada depois do prompt novo' => [self::story($ivanti['title'], $ivanti['summary'], $ivanti['cves'], $ivanti['affected_products'], $explained + [
                'indicators' => [
                    ['field' => 'cves', 'label' => 'CVEs', 'values' => $ivanti['cves']],
                    ['field' => 'related_cves', 'label' => 'CVEs relacionadas', 'values' => ['CVE-2026-96951']],
                    ['field' => 'affected_products', 'label' => 'Produtos', 'values' => $ivanti['affected_products']],
                ],
            ]), $chain],
            'Contexto marcado pela análise nunca conta' => [self::story(
                'Ivanti Connect Secure zero-day CVE-2026-104935 and CVE-2025-0282',
                'CVE-2026-104935 and CVE-2025-0282.',
                ['CVE-2026-104935', 'CVE-2025-0282'], [],
                ['indicators' => [['field' => 'related_cves', 'label' => 'CVEs relacionadas', 'values' => ['cve-2025-0282']]]],
            ), ['CVE-2026-104935']],
        ];
    }

    #[DataProvider('corpus')]
    public function test_main_cves(array $case, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, (new MainCves)->of((new NewsCase)->forceFill($case)));
    }

    public function test_lead_is_the_opening_paragraph_in_plain_text(): void
    {
        $this->assertSame('Ivanti fixed CVE-2026-1.', (new MainCves)->lead("**Ivanti** fixed `CVE-2026-1`.\n\nThe second paragraph.\n\n### Technical Details\n\nx"));
    }
}
