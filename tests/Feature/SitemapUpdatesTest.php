<?php

namespace Tests\Feature;

use App\Models\NewsCase;
use App\Services\Merge\CaseMerger;
use App\Services\Search\CaseIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\TestCase;

/**
 * O sitemap guardado no cache cai sozinho quando uma matéria é publicada, ganha
 * fonte, sai do feed ou volta, é fundida ou apagada (PublicCaseUrls), sem cron.
 */
class SitemapUpdatesTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://example.com']);
        // O Elasticsearch não faz parte da suíte.
        $this->mock(CaseIndex::class)->shouldIgnoreMissing();
    }

    /** @return array<string, ?string> uuid => lastmod das matérias no mapa */
    private function listed(): array
    {
        preg_match_all('#<url><loc>https://example\.com/materia/([0-9a-f-]{36})</loc>(?:<lastmod>([^<]+)</lastmod>)?</url>#', (string) $this->get('/sitemap.xml')->assertOk()->getContent(), $m);

        return array_combine($m[1], array_map(fn ($date) => $date ?: null, $m[2]));
    }

    public function test_the_sitemap_comes_from_the_cache_until_a_case_changes(): void
    {
        $first = $this->newsCase('Nginx RCE exploited in the wild');
        $this->assertSame([$first->uuid], array_keys($this->listed()));

        // Gravada por fora do modelo (como numa migration): o mapa guardado continua valendo.
        $quiet = (string) Str::uuid();
        DB::table('cases')->insert(['uuid' => $quiet, 'slug' => 'quiet', 'title' => 'Quiet', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$first->uuid], array_keys($this->listed()));

        // A matéria nova, pelo modelo, refaz o mapa (e ele traz também a de cima).
        $second = $this->newsCase('Ivanti zero-day');
        $this->assertSame([$first->uuid, $quiet, $second->uuid], array_keys($this->listed()));
    }

    public function test_a_new_source_moves_the_date_and_a_quiet_save_does_not_rebuild(): void
    {
        $case = $this->newsCase('Nginx RCE exploited in the wild', attributes: ['created_at' => Carbon::parse('2026-10-01 10:00:00'), 'latest_at' => Carbon::parse('2026-10-01 10:00:00')]);
        $this->assertSame('2026-10-01T10:00:00+00:00', $this->listed()[$case->uuid]);

        // O que o sitemap não mostra (as repetições, a indexação) não refaz o arquivo.
        $case->forceFill(['no_update_count' => 3])->save();
        DB::table('cases')->where('id', $case->id)->update(['latest_at' => '2026-10-09 10:00:00+00']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $this->listed()[$case->uuid]);

        $case->refresh()->forceFill(['latest_at' => Carbon::parse('2026-10-02 15:30:00')])->save();
        $this->assertSame('2026-10-02T15:30:00+00:00', $this->listed()[$case->uuid]);
    }

    public function test_hiding_returning_and_deleting_a_case_follow_in_the_sitemap(): void
    {
        $kept = $this->newsCase('Nginx RCE exploited in the wild');
        $case = $this->newsCase('Pixel 10 review', 'low');
        $this->assertCount(2, $this->listed());

        $case->hide('admin');
        $this->assertSame([$kept->uuid], array_keys($this->listed()));

        $case->unhide();
        $this->assertSame([$kept->uuid, $case->uuid], array_keys($this->listed()));

        $this->actingAs($this->adminUser())->delete(route('admin.cases.destroy', $case->uuid))->assertRedirect();
        $this->assertSame([$kept->uuid], array_keys($this->listed()));
    }

    public function test_the_fusion_swaps_the_old_cases_for_the_new_one(): void
    {
        $kept = $this->newsCase('Ahold Delhaize restores services');
        $one = $this->newsCase('Cloudflare sees attacks on two PHP flaws');
        $two = $this->newsCase('PHP fixes two critical RCE flaws');
        $this->assertCount(3, $this->listed());

        $new = app(CaseMerger::class)->merge([$one->id, $two->id], [
            'title' => 'PHP2Shell: two critical PHP RCE flaws face early attacks',
            'summary' => 'The PHP team fixes two critical flaws.',
            'title_pt' => null,
            'summary_pt' => null,
        ], 'mesma história');

        $this->assertInstanceOf(NewsCase::class, $new);
        // As antigas saem (o link delas redireciona para a nova), e a nova entra.
        $this->assertSame([$kept->uuid, $new->uuid], array_keys($this->listed()));
    }
}
