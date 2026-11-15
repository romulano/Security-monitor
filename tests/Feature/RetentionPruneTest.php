<?php

namespace Tests\Feature;

use App\Models\AlertMatch;
use App\Models\CrawlRun;
use App\Models\NotificationDelivery;
use App\Models\Source;
use App\Services\Alerts\AlertPanel;
use App\Services\Alerts\ProfileIndex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Alerts\AlertsTestHelpers;
use Tests\Feature\Alerts\FakeProfileIndex;
use Tests\Feature\Lookalike\LookalikeTestHelpers;
use Tests\TestCase;

/** secmon:prune: o que passou do prazo sai, o que está dentro do prazo fica, e a simulação não toca em nada. */
class RetentionPruneTest extends TestCase
{
    use AlertsTestHelpers, LookalikeTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTempBrowserDir();
        $this->app->instance(ProfileIndex::class, new FakeProfileIndex);
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserDir();

        parent::tearDown();
    }

    private function storedSession(string $id, int $lastActivity): void
    {
        DB::table('sessions')->insert(['id' => $id, 'payload' => 'x', 'last_activity' => $lastActivity]);
    }

    private function crawlRun(int $daysAgo): CrawlRun
    {
        $source = Source::query()->first() ?? Source::create(['name' => 'Feed', 'host' => 'feed.test', 'feed_url' => 'https://feed.test/rss']);

        return CrawlRun::create(['source_id' => $source->id, 'trigger' => 'schedule', 'status' => 'ok', 'started_at' => now()->subDays($daysAgo)]);
    }

    private function failedJob(int $daysAgo): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->subDays($daysAgo),
        ]);
    }

    private function delivery(int $daysAgo, array $attributes = []): NotificationDelivery
    {
        $user = $this->client();
        $channel = $this->slackChannel($user);
        $delivery = NotificationDelivery::query()->create($attributes + [
            'channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'realtime', 'status' => 'sent',
            'summary' => 'Nginx RCE', 'payload' => ['text' => 'texto enviado'],
        ]);
        $delivery->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $delivery;
    }

    public function test_expired_sessions_go_and_active_ones_stay(): void
    {
        config(['session.lifetime' => 240]);
        $this->storedSession('vencida', now()->subMinutes(241)->getTimestamp());
        $this->storedSession('ativa', now()->subMinutes(30)->getTimestamp());

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertSame(['ativa'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_old_crawl_runs_and_failed_jobs_go_by_their_own_deadlines(): void
    {
        $oldRun = $this->crawlRun(200);
        $recentRun = $this->crawlRun(10);
        $this->failedJob(45);
        $this->failedJob(5);

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertNull(CrawlRun::query()->find($oldRun->id));
        $this->assertNotNull(CrawlRun::query()->find($recentRun->id));
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_the_delivery_text_goes_first_and_the_row_later(): void
    {
        $recent = $this->delivery(10);
        $textGone = $this->delivery(120);
        $rowGone = $this->delivery(400);

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertSame(['text' => 'texto enviado'], $recent->fresh()->payload);
        $this->assertNull($textGone->fresh()->payload);
        $this->assertSame('Nginx RCE', $textGone->fresh()->summary, 'o registro de que houve envio fica');
        $this->assertNull(NotificationDelivery::query()->find($rowGone->id));
    }

    public function test_a_zero_in_the_config_switches_that_item_off(): void
    {
        config(['secmon.retention.crawl_runs_days' => 0, 'secmon.retention.delivery_days' => 0]);
        $run = $this->crawlRun(900);
        $delivery = $this->delivery(900);

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertNotNull(CrawlRun::query()->find($run->id));
        $this->assertNotNull(NotificationDelivery::query()->find($delivery->id));
    }

    public function test_dry_run_counts_and_changes_nothing(): void
    {
        config(['session.lifetime' => 240]);
        $this->storedSession('vencida', now()->subDays(2)->getTimestamp());
        $run = $this->crawlRun(200);
        $delivery = $this->delivery(120);

        $this->artisan('secmon:prune', ['--dry-run' => true])
            ->expectsOutputToContain('O que seria apagado')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertNotNull(CrawlRun::query()->find($run->id));
        $this->assertNotNull($delivery->fresh()->payload);
    }

    public function test_it_deletes_in_batches_until_nothing_is_left(): void
    {
        $rows = array_map(fn ($n) => [
            'uuid' => '00000000-0000-4000-8000-'.str_pad((string) $n, 12, '0', STR_PAD_LEFT), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->subDays(60),
        ], range(1, 5200));
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('failed_jobs')->insert($chunk);
        }

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_old_radar_captures_are_pruned_too(): void
    {
        $lookalike = $this->lookalike($this->watch(), 'acmepay-velho.com');
        $old = $lookalike->captures()->create(['status' => 'done', 'requested_at' => now(), 'keep' => false]);
        $old->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();

        $this->artisan('secmon:prune')->assertExitCode(0);

        $this->assertNull($old->fresh());
    }

    public function test_it_is_scheduled_every_day_at_dawn(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'secmon:prune'));

        $this->assertNotNull($event, 'secmon:prune não está no agendador');
        $this->assertSame('40 3 * * *', $event->expression);
    }

    public function test_deleting_an_alert_deletes_what_was_sent_for_it_and_only_for_it(): void
    {
        $user = $this->client();
        $channel = $this->slackChannel($user);
        $doomed = $this->profile($user, ['company_name' => 'Acme A'], ['nginx']);
        $kept = $this->profile($user, ['company_name' => 'Acme B'], ['laravel']);
        $case = $this->newsCase('Falha qualquer');

        $make = fn ($profile) => AlertMatch::query()->create([
            'profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now(),
        ]);
        $doomedMatch = $make($doomed);
        $keptMatch = $make($kept);

        $send = fn ($match) => NotificationDelivery::query()->create([
            'channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'realtime', 'status' => 'sent',
            'match_id' => $match->id, 'payload' => ['text' => 'enviado'],
        ]);
        $doomedDelivery = $send($doomedMatch);
        $keptDelivery = $send($keptMatch);

        app(AlertPanel::class)->delete($doomed);

        $this->assertNull(NotificationDelivery::query()->find($doomedDelivery->id));
        $this->assertNotNull(NotificationDelivery::query()->find($keptDelivery->id));
        $this->assertNull($doomed->fresh());
    }
}
