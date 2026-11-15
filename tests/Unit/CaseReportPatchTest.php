<?php

namespace Tests\Unit;

use App\Services\Enrichment\CaseReportPatch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CaseReportPatchTest extends TestCase
{
    private const EN = "The **PHP** team fixed two flaws.\n\n### Technical Details\n- Out-of-memory corruption.\n\n### Remediation\n- Update to 8.4.27.";

    private const PT = "A equipe do **PHP** corrigiu duas falhas.\n\n### Detalhes Técnicos\n- Falta de memória.\n\n### Remediação\n- Atualize para a 8.4.27.";

    public function test_replaces_only_the_parts_in_the_patch(): void
    {
        $report = CaseReportPatch::apply(self::EN, ['technical' => "- Out-of-memory corruption.\n- Stale global state (bleepingcomputer.com)."], 'en');

        $this->assertSame(
            "The **PHP** team fixed two flaws.\n\n### Technical Details\n- Out-of-memory corruption.\n- Stale global state (bleepingcomputer.com).\n\n### Remediation\n- Update to 8.4.27.",
            $report,
        );
    }

    public function test_adds_a_part_the_report_did_not_have_under_the_heading_of_its_language(): void
    {
        $report = CaseReportPatch::apply(self::PT, ['fix' => '- PR #62297, aceito em 03/11.', 'body' => null], 'pt');

        $this->assertSame(
            "A equipe do **PHP** corrigiu duas falhas.\n\n### Detalhes Técnicos\n- Falta de memória.\n\n### Detalhes da correção\n- PR #62297, aceito em 03/11.\n\n### Remediação\n- Atualize para a 8.4.27.",
            $report,
        );
    }

    public function test_an_empty_text_removes_the_part_and_null_keeps_it(): void
    {
        $report = CaseReportPatch::apply(self::EN, ['remediation' => '', 'technical' => null], 'en');

        $this->assertSame("The **PHP** team fixed two flaws.\n\n### Technical Details\n- Out-of-memory corruption.", $report);
        $this->assertSame(self::EN, CaseReportPatch::apply(self::EN, [], 'en'));
    }

    public function test_refuses_a_part_the_report_does_not_have(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CaseReportPatch::apply(self::EN, ['timeline' => '- 2026-10-20: leak.'], 'en');
    }

    public function test_gives_up_when_it_cannot_fit_the_patch(): void
    {
        // Sem relatório, com linha do tempo (o split a descartaria) ou sem a abertura.
        $this->assertNull(CaseReportPatch::apply(null, ['technical' => '- New.'], 'en'));
        $this->assertNull(CaseReportPatch::apply("   \n", ['technical' => '- New.'], 'en'));
        $this->assertNull(CaseReportPatch::apply("Opening.\n\n### Timeline\n- 2026-10-20: leak.", ['technical' => '- New.'], 'en'));
        $this->assertNull(CaseReportPatch::apply(self::EN, ['body' => ''], 'en'));
    }
}
