<?php

namespace Tests\Unit;

use App\Support\ReportSections;
use PHPUnit\Framework\TestCase;

class ReportSectionsTest extends TestCase
{
    public function test_splits_the_report_and_drops_its_timeline(): void
    {
        $parts = ReportSections::split(implode("\n", [
            'The **PHP** team fixed two flaws.',
            '',
            '### Timeline',
            '- 2026-10-20: leak on Hacker News.',
            '',
            '### Technical Details',
            '- Out-of-memory corruption.',
            '',
            '### Remediation',
            '- Update to 8.4.27.',
            '',
            '### Conflicting Reports',
            '- CVSS 9.2 versus 10.0.',
        ]));

        $this->assertSame('The **PHP** team fixed two flaws.', $parts['body']);
        $this->assertSame('- Out-of-memory corruption.', $parts['technical']);
        $this->assertSame('- Update to 8.4.27.', $parts['remediation']);
        $this->assertSame('- CVSS 9.2 versus 10.0.', $parts['conflicting']);
        $this->assertStringNotContainsString('leak on Hacker News', implode(' ', $parts));
    }

    public function test_reads_the_portuguese_headings(): void
    {
        $parts = ReportSections::split("Abertura.\n\n### Linha do tempo\n- 20/10: vazamento.\n\n### Detalhes Técnicos\n- Falta de memória.\n\n### Remediação\n- Atualize.\n\n## Relatos divergentes:\n- 9,2 contra 10,0.");

        $this->assertSame('Abertura.', $parts['body']);
        $this->assertSame('- Falta de memória.', $parts['technical']);
        $this->assertSame('- Atualize.', $parts['remediation']);
        $this->assertSame('- 9,2 contra 10,0.', $parts['conflicting']);
    }

    public function test_keeps_what_it_does_not_know_and_code_blocks(): void
    {
        $parts = ReportSections::split(implode("\n", [
            'Opening.',
            '### Affected Versions',
            '- 5.4 onward.',
            '### Technical Details',
            '```',
            '### not a heading',
            '```',
            '### Detection',
            '- Watch php-fpm children.',
            '### Timeline',
            '- 2026-10-21: patch.',
            '### Impact',
            '- 70% of sites.',
        ]));

        // Título desconhecido fica onde estava; depois da linha do tempo, volta para o conteúdo.
        $this->assertSame("Opening.\n### Affected Versions\n- 5.4 onward.\n### Impact\n- 70% of sites.", $parts['body']);
        $this->assertSame("```\n### not a heading\n```\n### Detection\n- Watch php-fpm children.", $parts['technical']);
        $this->assertNull($parts['remediation']);
        $this->assertNull($parts['conflicting']);
        $this->assertSame(['body' => '', 'technical' => null, 'fix' => null, 'remediation' => null, 'conflicting' => null], ReportSections::split(null));
    }

    public function test_the_fix_found_by_the_research_has_its_own_section(): void
    {
        $en = ReportSections::split("Opening.\n\n### Technical Details\n- Output injection.\n\n### Fix Details\n- PR #62297, merged on 3 November.\n\n### Remediation\n- Update.");
        $pt = ReportSections::split("Abertura.\n\n### Detalhes da correção\n- PR #62297, aceito em 03/11.");

        $this->assertSame('- Output injection.', $en['technical']);
        $this->assertSame('- PR #62297, merged on 3 November.', $en['fix']);
        $this->assertSame('- Update.', $en['remediation']);
        $this->assertSame('- PR #62297, aceito em 03/11.', $pt['fix']);
        $this->assertSame('Abertura.', $pt['body']);
    }

    public function test_joins_the_parts_back_in_the_order_of_the_story(): void
    {
        $en = "Opening with **PHP**.\n\n### Technical Details\n- Out-of-memory corruption.\n\n### Fix Details\n- PR #62297.\n\n### Remediation\n- Update to 8.4.27.\n\n### Conflicting Reports\n- CVSS 9.2 versus 10.0.";
        $pt = "Abertura sobre o **PHP**.\n\n### Detalhes Técnicos\n- Falta de memória.\n\n### Detalhes da correção\n- PR #62297.\n\n### Remediação\n- Atualize.\n\n### Relatos divergentes\n- 9,2 contra 10,0.";

        $this->assertSame($en, ReportSections::join(ReportSections::split($en), 'en'));
        $this->assertSame($pt, ReportSections::join(ReportSections::split($pt), 'pt'));

        // Fora de ordem e com partes vazias: sai na ordem da matéria, sem título vazio.
        $this->assertSame(
            "Opening.\n\n### Remediation\n- Update.",
            ReportSections::join(['remediation' => '- Update.', 'technical' => '  ', 'fix' => null, 'body' => 'Opening.'], 'en'),
        );
    }

    public function test_the_round_trip_keeps_unknown_headings_and_code_blocks(): void
    {
        $report = "Opening.\n### Affected Versions\n- 5.4 onward.\n\n### Technical Details\n```\n### not a heading\n```\n### Detection\n- Watch php-fpm children.";

        $again = ReportSections::split(ReportSections::join(ReportSections::split($report), 'en'));

        $this->assertSame("Opening.\n### Affected Versions\n- 5.4 onward.", $again['body']);
        $this->assertSame("```\n### not a heading\n```\n### Detection\n- Watch php-fpm children.", $again['technical']);
    }

    public function test_join_refuses_an_unknown_language(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReportSections::join(['body' => 'Opening.'], 'es');
    }

    public function test_tells_when_the_report_has_a_timeline(): void
    {
        $this->assertTrue(ReportSections::hasTimeline("Opening.\n\n### Timeline\n- 2026-10-20: leak."));
        $this->assertTrue(ReportSections::hasTimeline("Abertura.\n\n## Linha do tempo:\n- 20/10: vazamento."));
        $this->assertFalse(ReportSections::hasTimeline("Opening.\n\n```\n### Timeline\n```"));
        $this->assertFalse(ReportSections::hasTimeline("Opening.\n\n### Technical Details\n- A timeline of the attack."));
        $this->assertFalse(ReportSections::hasTimeline(null));
    }
}
