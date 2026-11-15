<?php

namespace Tests\Feature;

use App\Jobs\MatchCaseToProfiles;
use App\Jobs\ReviewAlertMatches;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\NotificationDelivery;
use App\Services\Alerts\DigestBuilder;
use App\Services\Alerts\ProfileIndex;
use App\Services\Crawler\CaseResolver;
use App\Services\Search\CaseIndex;
use App\Services\Telemetry;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Feature\Search\FakeElasticsearch;
use Tests\TestCase;

/**
 * Matéria fora do feed público (2026-11-01), sem apagar: some da página (410),
 * da API, da busca, da atividade recente, do contador da /sobre e dos alertas;
 * o admin continua vendo e devolve ao feed na página dela.
 */
class HiddenCasesTest extends TestCase
{
    use AlertsTestHelpers;
    use RefreshDatabase;

    private const GENERAL = 'https://techcrunch.com/feed/';

    /** Matéria com um artigo de cada feed indicado. */
    private function caseFrom(string $title, string $criticity, array $feeds): NewsCase
    {
        $case = NewsCase::query()->create(['title' => $title, 'criticity' => $criticity, 'summary' => 'Resumo.', 'first_at' => now(), 'latest_at' => now()]);

        foreach ($feeds as $feed) {
            $this->article($case, ['feed_url' => $feed, 'source_host' => parse_url($feed, PHP_URL_HOST)]);
        }

        return $case;
    }

    private function match(AlertProfile $profile, NewsCase $case, array $attributes = []): AlertMatch
    {
        return AlertMatch::query()->create($attributes + [
            'profile_id' => $profile->id, 'case_id' => $case->id, 'article_id' => $case->articles()->value('id'),
            'kind' => 'new_case', 'criticity' => $case->criticity, 'score' => 80,
            'reasons' => [['type' => 'stack', 'label' => 'nginx', 'weight' => 80]], 'delivery_status' => 'sent', 'matched_at' => now(),
        ]);
    }

    public function test_the_migration_hides_only_the_low_cases_that_came_only_from_the_general_techcrunch_feed(): void
    {
        $review = $this->caseFrom('Pixel 10 review', 'low', [self::GENERAL]);
        $event = $this->caseFrom('Disrupt 2026 tickets on sale', 'low', [self::GENERAL, self::GENERAL]);
        $this->caseFrom('Startup leaks customer data', 'low', [self::GENERAL, 'https://www.bleepingcomputer.com/feed/']);
        $this->caseFrom('Retailer confirms breach', 'medium', [self::GENERAL]);
        $this->caseFrom('Security startup raises Series B', 'low', ['https://www.securityweek.com/rss.xml']);

        $migration = require database_path('migrations/2026_11_21_000400_hide_off_topic_techcrunch_cases.php');
        $migration->up();

        $this->assertSame([$review->id, $event->id], NewsCase::query()->where('is_published', false)->orderBy('id')->pluck('id')->all());
        $this->assertSame('off_topic_feed', $review->refresh()->hidden_reason);
        $this->assertTrue($review->hidden_at->isToday());
        // Nada é apagado.
        $this->assertSame([5, 7], [NewsCase::query()->count(), Article::query()->count()]);

        $migration->down();
        $this->assertFalse(NewsCase::query()->where('is_published', false)->exists());
    }

    public function test_the_page_of_a_hidden_case_is_gone_for_the_public_and_open_for_the_admin(): void
    {
        $case = $this->newsCase('Pixel 10 review', 'low');
        $case->hide('off_topic_feed');

        $this->get(route('feed.show', $case->uuid))->assertStatus(410)->assertDontSee('Pixel 10 review');
        $this->getJson('/api/news/case/'.$case->uuid)->assertNotFound()->assertExactJson(['error' => 'not_found']);
        // A que não existe continua 404.
        $this->get(route('feed.show', (string) Str::uuid()))->assertNotFound();

        $this->actingAs($this->client())->get(route('feed.show', $case->uuid))->assertStatus(410);

        $this->actingAs($this->adminUser())->get(route('feed.show', $case->uuid))
            ->assertOk()
            ->assertSee('Pixel 10 review')
            ->assertSee('Fora do feed público')
            ->assertSee(NewsCase::HIDDEN_REASONS['off_topic_feed'])
            ->assertSee('Devolver ao feed');
    }

    public function test_the_admin_takes_a_case_off_the_feed_and_back_and_the_search_follows(): void
    {
        $case = $this->newsCase('Disrupt 2026 tickets on sale', 'low');
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json(['result' => 'deleted']),   // tirar do feed: sai do índice
            FakeElasticsearch::json(['result' => 'created']),   // devolver: volta ao índice
        ]);
        $this->app->instance(CaseIndex::class, $es->caseIndex());
        $admin = $this->adminUser();
        $page = route('feed.show', $case->uuid);

        $this->actingAs($admin)->get($page)->assertSee('Admin: tirar do feed público');

        $this->actingAs($admin)->from($page)->post(route('admin.cases.visibility', $case->uuid))
            ->assertRedirect($page)
            ->assertSessionHas('status');

        $case->refresh();
        $this->assertSame([false, 'admin'], [$case->is_published, $case->hidden_reason]);
        $this->assertSame(['DELETE', '/secmon_cases/_doc/'.$case->uuid], [$es->method(0), $es->path(0)]);

        $this->actingAs($admin)->from($page)->post(route('admin.cases.visibility', $case->uuid))->assertRedirect($page);

        $case->refresh();
        $this->assertSame([true, null, null], [$case->is_published, $case->hidden_reason, $case->hidden_at]);
        $this->assertSame(['/secmon_cases/_doc/'.$case->uuid, $case->uuid], [$es->path(1), $es->body(1)['uuid']]);
        $this->assertNotNull($case->indexed_at);
    }

    public function test_writing_or_reindexing_never_puts_a_hidden_case_back_in_the_search(): void
    {
        $visible = $this->newsCase('Nginx RCE exploited');
        $hidden = $this->newsCase('Pixel 10 review', 'low');
        $hidden->hide('off_topic_feed');
        $es = FakeElasticsearch::answering([
            FakeElasticsearch::json(['result' => 'deleted']),             // put da escondida
            FakeElasticsearch::json(['errors' => false, 'items' => []]),  // bulk das duas
            FakeElasticsearch::json(['errors' => false, 'items' => []]),  // pruneHidden
        ]);
        $index = $es->caseIndex();

        // Fonte nova numa matéria escondida (o CaseWriter grava pelo put): ela sai da busca em vez de entrar.
        $index->put($hidden);
        $this->assertSame('DELETE', $es->method(0));

        $this->assertSame(1, $index->bulk([$visible, $hidden]));
        $lines = array_map(fn ($line) => json_decode($line, true), array_values(array_filter(explode("\n", (string) $es->requests()[1]->getBody()))));
        $this->assertSame(['index', 'uuid', 'delete'], array_map(fn ($line) => array_key_first($line), $lines));
        $this->assertSame($hidden->uuid, $lines[2]['delete']['_id']);
        $this->assertNull($hidden->refresh()->indexed_at);

        // Escondida direto no banco (a migration) e ainda na busca: o boot e o reindex a tiram.
        $hidden->forceFill(['indexed_at' => now()])->save();
        $this->assertSame(1, $index->pruneHidden());
        $this->assertSame(['delete' => ['_index' => 'secmon_cases', '_id' => $hidden->uuid]], json_decode(trim((string) $es->requests()[2]->getBody()), true));
        $this->assertSame(0, $index->pruneHidden());
        $this->assertCount(3, $es->requests());
    }

    public function test_the_boot_takes_out_of_the_search_what_a_migration_hid(): void
    {
        $this->mock(CaseIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('ping')->andReturn(true);
            $mock->shouldReceive('index')->andReturn('secmon_cases');
            $mock->shouldReceive('properties')->andReturn([]);
            $mock->shouldReceive('create');
            $mock->shouldReceive('addMissingFields')->andReturn([]);
            $mock->shouldReceive('pruneHidden')->once()->andReturn(22);
        });
        $this->mock(ProfileIndex::class, function (MockInterface $mock) {
            $mock->shouldReceive('name')->andReturn('secmon_profiles');
            $mock->shouldReceive('create');
        });

        $this->artisan('secmon:es-setup')
            ->expectsOutputToContain('22 matéria(s) escondida(s) do feed público saíram da busca.')
            ->assertExitCode(0);
    }

    public function test_the_recent_activity_and_the_about_counter_leave_hidden_cases_out(): void
    {
        $this->newsCase('Nginx RCE exploited');
        $hidden = $this->newsCase('Pixel 10 review', 'low');
        $hidden->hide('off_topic_feed');

        $updates = $this->getJson('/api/news/updates')->assertOk()->json('updates');

        $this->assertSame(['Nginx RCE exploited'], array_column($updates, 'title'));
        $this->assertSame(1, app(Telemetry::class)->snapshot()['cases']);
    }

    public function test_a_hidden_case_raises_no_alert_and_the_held_one_is_not_sent(): void
    {
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $user = $this->client();
        $this->slackChannel($user);
        $profile = $this->profile($user, [], ['nginx']);

        // Escondida antes do matching: nem vira alerta.
        $hidden = $this->newsCase('Nginx in a phone review', 'high', ['affected_products' => ['nginx']]);
        $hidden->hide('admin');
        MatchCaseToProfiles::dispatchSync($hidden->id, $hidden->articles()->value('id'));
        $this->assertFalse(AlertMatch::query()->exists());

        // Segurado pelo horário silencioso e escondida depois: o flush não manda.
        $case = $this->newsCase('Nginx RCE exploited', 'high', ['affected_products' => ['nginx']]);
        $held = $this->match($profile, $case, ['delivery_status' => 'held', 'delivery_note' => 'quiet_hours', 'release_at' => now()->subMinute()]);
        $case->hide('admin');

        $this->artisan('secmon:alerts-flush')->assertSuccessful();

        $this->assertSame(['skipped', 'hidden', null], [$held->refresh()->delivery_status, $held->delivery_note, $held->release_at]);
        $this->assertSame('a matéria saiu do feed público', $held->deliveryLabel());
        Http::assertNothingSent();
    }

    public function test_the_ai_does_not_judge_a_case_that_left_the_feed_while_waiting(): void
    {
        Settings::setMany(['alerts.ai_review_enabled' => true, 'enricher.hermes_url' => 'http://hermes.test']);
        Http::fake();
        $user = $this->client();
        $this->slackChannel($user);
        $case = $this->newsCase('Nginx RCE exploited', 'critical', ['affected_products' => ['nginx']]);
        $match = $this->match($this->profile($user, [], ['nginx']), $case, ['delivery_status' => 'pending', 'delivery_note' => 'ai_review']);
        $case->hide('admin');

        ReviewAlertMatches::dispatchSync([$match->id]);

        $this->assertSame(['skipped', 'hidden'], [$match->refresh()->delivery_status, $match->delivery_note]);
        $this->assertNull($match->ai_reviewed_at);
        Http::assertNothingSent();
    }

    public function test_resending_the_alert_of_a_hidden_case_is_refused(): void
    {
        Http::fake();
        $user = $this->client();
        $this->slackChannel($user);
        $case = $this->newsCase('Nginx RCE exploited');
        $match = $this->match($this->profile($user, [], ['nginx']), $case);
        $case->hide('admin');

        $this->actingAs($user)->from(route('alerts.index'))->post(route('alerts.resend', $match->id))
            ->assertRedirect(route('alerts.index'))
            ->assertSessionHas('error', 'Essa matéria saiu do feed público (não é de segurança) e não é mais enviada.');

        Http::assertNothingSent();
        $this->assertFalse(NotificationDelivery::query()->exists());
    }

    public function test_the_daily_digest_leaves_hidden_cases_out(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00'); // 09:00 em Brasília
        $user = $this->client();
        $channel = $this->slackChannel($user, ['digest_scope' => 'all']);
        $profile = $this->profile($user, [], ['nginx']);
        $visible = $this->newsCase('Nginx RCE exploited', 'critical');
        $hidden = $this->newsCase('Pixel 10 review', 'critical');
        $this->match($profile, $visible);
        $this->match($profile, $hidden);
        $this->newsCase('Pixel 11 rumor', 'high')->hide('admin');
        $hidden->hide('admin');

        $digest = app(DigestBuilder::class)->build($channel->refresh(), Carbon::parse('2026-10-21', 'America/Sao_Paulo'));

        $this->assertSame([$visible->id], array_map(fn (array $item) => $item['case']->id, $digest['mine']));
        $this->assertSame([], $digest['panorama'], 'só a visível era critical ou high, e ela já está em "os seus"');
        $this->assertSame(1, $digest['total']);
    }

    public function test_a_new_article_opens_its_own_case_instead_of_joining_a_hidden_one(): void
    {
        $resolver = app(CaseResolver::class);
        $title = 'Apple unveils the iPhone 18 Pro with satellite messaging';
        $hidden = $this->newsCase($title, 'low', attributes: ['fingerprint' => $resolver->fingerprint($title)]);
        $article = new Article(['original_title' => $title]);

        $this->assertSame($hidden->id, $resolver->resolve($article)?->id);

        $hidden->hide('off_topic_feed');
        $this->assertNull($resolver->resolve($article));
    }

    public function test_the_admin_finds_the_hidden_cases_in_collections_and_on_the_dashboard(): void
    {
        $this->newsCase('Nginx RCE exploited');
        $hidden = $this->newsCase('Pixel 10 review', 'low');
        $hidden->hide('off_topic_feed');
        $admin = $this->adminUser();

        $this->actingAs($admin)->get(route('admin.collections', ['status' => 'fora_do_feed']))
            ->assertOk()
            ->assertSee('Pixel 10 review')
            ->assertDontSee('Nginx RCE exploited')
            ->assertSee('fora do feed');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Fora do feed público')
            ->assertSee('href="'.route('admin.collections', ['status' => 'fora_do_feed']).'"', false);
    }
}
