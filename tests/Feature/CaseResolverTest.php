<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\NewsCase;
use App\Services\Crawler\CaseResolver;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseResolverTest extends TestCase
{
    use RefreshDatabase;

    private CaseResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(CaseResolver::class);
        Settings::flush();
    }

    private function makeCase(string $title, array $cves = []): NewsCase
    {
        return NewsCase::create([
            'title' => $title,
            'fingerprint' => $this->resolver->fingerprint($title),
            'cves' => $cves,
            'criticity' => 'high',
            'first_at' => now(),
            'latest_at' => now(),
            'source_count' => 1,
            'update_count' => 1,
        ]);
    }

    private function makeArticle(string $title): Article
    {
        return Article::create([
            'url' => 'https://example.test/'.md5($title),
            'url_hash' => hash('sha256', $title),
            'original_title' => $title,
            'title' => $title,
            'source_host' => 'example.test',
            'collected_at' => now(),
            'status' => 'pending',
        ]);
    }

    public function test_agrupa_quando_a_mesma_cve_e_o_assunto_coincidem(): void
    {
        $case = $this->makeCase(
            'CVE-2026-61511: vBulletin Preauth RCE with Public PoC Disclosed',
            ['CVE-2026-61511']
        );

        $article = $this->makeArticle('Public Exploit Released for Patched vBulletin Pre-Auth Code Execution');

        $this->assertSame($case->id, $this->resolver->resolve($article, ['CVE-2026-61511'])?->id);
    }

    public function test_nao_agrupa_assuntos_diferentes(): void
    {
        $this->makeCase('Clop ransomware targets Windchill in data theft attacks');

        $article = $this->makeArticle('Cloudflare open sources its privacy proxy CLI');

        $this->assertNull($this->resolver->resolve($article));
    }

    /**
     * Feeds com título em molde fixo ("Have I Been Pwned: <empresa> Data
     * Breach") passavam do limiar de similaridade só pelo molde e colapsavam
     * vazamentos distintos num único caso.
     */
    public function test_nao_agrupa_titulos_que_so_compartilham_o_molde_do_feed(): void
    {
        foreach (['DentaQuest', 'Acme Corp', 'Globex', 'Initech', 'Umbrella'] as $victim) {
            $this->makeCase("Have I Been Pwned: {$victim} Data Breach");
        }

        $article = $this->makeArticle('Have I Been Pwned: Soylent Data Breach');

        $this->assertNull(
            $this->resolver->resolve($article),
            'Vazamentos de empresas diferentes não podem virar o mesmo caso.'
        );
    }

    public function test_agrupa_quando_alem_do_molde_ha_termo_especifico_em_comum(): void
    {
        foreach (['Acme Corp', 'Globex', 'Initech', 'Umbrella'] as $victim) {
            $this->makeCase("Have I Been Pwned: {$victim} Data Breach");
        }

        $case = $this->makeCase('Have I Been Pwned: DentaQuest Data Breach');
        $article = $this->makeArticle('Have I Been Pwned: DentaQuest Data Breach');

        $this->assertSame($case->id, $this->resolver->resolve($article)?->id);
    }

    public function test_ignora_casos_fora_da_janela_de_deduplicacao(): void
    {
        $case = $this->makeCase('Clop ransomware targets Windchill in data theft attacks');
        $case->forceFill(['latest_at' => now()->subDays(60)])->save();

        $article = $this->makeArticle('Clop ransomware targets Windchill in data theft attacks');

        $this->assertNull($this->resolver->resolve($article));
    }

    public function test_devolve_o_modelo_completo_para_os_contadores_incrementarem(): void
    {
        $case = $this->makeCase('Clop ransomware targets Windchill in data theft attacks');
        $case->forceFill(['update_count' => 4])->save();

        $resolved = $this->resolver->resolve(
            $this->makeArticle('Clop ransomware targets Windchill in data theft attacks')
        );

        $this->assertNotNull($resolved);
        $this->assertSame(4, $resolved->update_count);
    }
}
