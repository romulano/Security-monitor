<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Exploit;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use App\Support\ReportLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ordem da matéria: conteúdo, entenda a falha, exploits, detalhes técnicos,
 * remediação, relatos divergentes e linha do tempo (a das fontes; a do
 * relatório da IA não aparece).
 */
class CasePageOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_story_follows_the_editorial_order_with_a_single_timeline(): void
    {
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();

        $report = fn (string $lang) => implode("\n", $lang === 'pt'
            ? ['A equipe do **PHP** corrigiu duas falhas.', '', '### Linha do tempo', '- 20/10: vazamento no Hacker News.', '',
                '### Detalhes Técnicos', '- Corrupção por falta de memória.', '', '### Remediação', '- Atualize para a 8.4.27.', '',
                '### Relatos divergentes', '- CVSS 9,2 contra 10,0.']
            : ['The **PHP** team fixed two flaws.', '', '### Timeline', '- 2026-10-20: leak on Hacker News.', '',
                '### Technical Details', '- Out-of-memory corruption.', '', '### Remediation', '- Update to 8.4.27.', '',
                '### Conflicting Reports', '- CVSS 9.2 versus 10.0.']);

        $case = NewsCase::query()->create([
            'title' => 'PHP2Shell: two critical PHP RCE flaws',
            'title_pt' => 'PHP2Shell: duas falhas críticas de RCE no PHP',
            'summary' => $report('en'),
            'summary_pt' => $report('pt'),
            'criticity' => 'critical',
            'cves' => ['CVE-2026-109618'],
            'first_at' => now()->subHour(),
            'latest_at' => now(),
            'vulnerability_applies' => true,
            'vulnerability_explanation' => 'Two memory-safety flaws in the PHP engine.',
            'vulnerability_explanation_pt' => 'Duas falhas de segurança de memória no motor do PHP.',
            'vulnerability_explained_at' => now(),
        ]);

        Article::query()->create([
            'case_id' => $case->id,
            'url' => 'https://bleepingcomputer.com/php2shell',
            'url_hash' => hash('sha256', 'php2shell'),
            'source_host' => 'bleepingcomputer.com',
            'original_title' => 'PHP fixes php2shell',
            'title' => 'PHP releases fixes for the php2shell flaws',
            'summary' => 'Analysis of this source.',
            'status' => 'enriched',
            'is_first' => true,
            'collected_at' => now(),
        ]);

        Exploit::query()->create(['id' => 52999, 'file' => 'exploits/php/webapps/52999.py', 'description' => 'PHP 8.4 - Remote Code Execution', 'type' => 'webapps', 'platform' => 'php', 'author' => 'x', 'verified' => false, 'published_on' => '2026-10-21']);
        DB::table('exploit_cve')->insert(['exploit_id' => 52999, 'cve' => 'CVE-2026-109618']);
        DB::table('case_exploit')->insert(['case_id' => $case->id, 'exploit_id' => 52999, 'created_at' => now()]);

        $this->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSeeInOrder([
                'team fixed two flaws',
                'Entenda a falha', 'Two memory-safety flaws',
                'Exploit público', 'PHP 8.4 - Remote Code Execution',
                'Detalhes técnicos', 'Out-of-memory corruption.',
                'Remediação', 'Update to 8.4.27.',
                'Relatos divergentes', 'CVSS 9.2 versus 10.0.',
                'Linha do tempo', 'PHP releases fixes for the php2shell flaws',
            ])
            ->assertDontSee('leak on Hacker News')
            ->assertDontSee('Timeline');

        $this->withCookie(ReportLanguage::COOKIE, 'pt')->get(route('feed.show', $case->uuid))
            ->assertSeeInOrder(['corrigiu duas falhas', 'Entenda a falha', 'Detalhes técnicos', 'Corrupção por falta de memória.', 'Remediação', 'Relatos divergentes', 'Linha do tempo'])
            ->assertDontSee('vazamento no Hacker News');

        // A API segue com o relatório inteiro, no formato de sempre.
        $this->getJson('/api/news/case/'.$case->uuid)->assertOk()
            ->assertJsonPath('latest_summary', $report('en'))
            ->assertJsonMissingPath('report');
    }
}
