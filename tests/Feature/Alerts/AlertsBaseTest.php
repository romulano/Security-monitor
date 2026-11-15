<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\InterviewQuestion;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\TechCatalog;
use App\Support\Settings;
use Database\Seeders\InterviewQuestionSeeder;
use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Etapa 1: tabelas, catálogo, perguntas e configurações. */
class AlertsBaseTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    public function test_catalog_is_seeded_with_frameworks_under_their_language(): void
    {
        $laravel = TechCatalog::query()->where('slug', 'laravel')->firstOrFail();

        $this->assertSame('framework', $laravel->category);
        $this->assertSame('php', $laravel->parent->slug);
        $this->assertContains('laravel', $laravel->terms);
        $this->assertSame([['type' => 'cookie', 'pattern' => 'laravel_session']], $laravel->detect);

        foreach (array_keys(TechCatalog::CATEGORIES) as $category) {
            $this->assertTrue(TechCatalog::query()->where('category', $category)->exists(), "categoria {$category} vazia");
        }
    }

    public function test_every_interview_step_but_review_has_questions(): void
    {
        foreach (range(1, 6) as $step) {
            $this->assertTrue(InterviewQuestion::query()->where('step', $step)->exists(), "passo {$step} sem perguntas");
        }

        $this->assertFalse(InterviewQuestion::query()->where('step', 7)->exists());

        $frameworks = InterviewQuestion::query()->where('key', 'frameworks')->firstOrFail();
        $this->assertSame('languages', $frameworks->filter_by);
        // jsonb não guarda a ordem das chaves.
        $this->assertEquals(['key' => 'uses_framework', 'in' => ['sim']], $frameworks->show_if);
        $this->assertTrue($frameworks->is_system);
        $this->assertSame('sim / não', InterviewQuestion::query()->where('key', 'uses_framework')->firstOrFail()->kindLabel());
    }

    public function test_seeders_only_create_what_is_missing(): void
    {
        $laravel = TechCatalog::query()->where('slug', 'laravel')->firstOrFail();
        $laravel->update(['terms' => ['laravel', 'termo do admin']]);
        InterviewQuestion::query()->where('key', 'company_name')->update(['prompt' => 'Razão social']);
        $techs = TechCatalog::query()->count();

        (new TechCatalogSeeder)->run();
        (new InterviewQuestionSeeder)->run();

        $this->assertSame(['laravel', 'termo do admin'], $laravel->refresh()->terms);
        $this->assertSame('Razão social', InterviewQuestion::query()->where('key', 'company_name')->value('prompt'));
        $this->assertSame($techs, TechCatalog::query()->count());
    }

    public function test_webhook_is_encrypted_at_rest_and_masked_on_screen(): void
    {
        $channel = $this->slackChannel($this->client());
        $raw = DB::table('notification_channels')->where('id', $channel->id)->value('webhook_url');

        $this->assertStringNotContainsString('hooks.slack.com', $raw);
        $this->assertSame('https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx', $channel->refresh()->webhook_url);
        $this->assertSame('hooks.slack.com/…/uvwx', $channel->maskedWebhook());
        $this->assertArrayNotHasKey('webhook_url', $channel->toArray());
    }

    public function test_quiet_hours_crossing_midnight_in_the_channel_timezone(): void
    {
        $channel = new NotificationChannel(['quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'America/Sao_Paulo']);

        // 23:30 em São Paulo = 02:30 UTC; segura até 07:00 local = 10:00 UTC.
        $until = $channel->quietUntil(Carbon::parse('2026-10-20 02:30:00', 'UTC'));
        $this->assertSame('2026-10-20 10:00:00', $until->format('Y-m-d H:i:s'));

        // 06:59 local ainda é silêncio; 07:00 local já não é.
        $this->assertNotNull($channel->quietUntil(Carbon::parse('2026-10-20 09:59:00', 'UTC')));
        $this->assertNull($channel->quietUntil(Carbon::parse('2026-10-20 10:00:00', 'UTC')));
        $this->assertNull($channel->quietUntil(Carbon::parse('2026-10-20 15:00:00', 'UTC')));

        $this->assertNull((new NotificationChannel)->quietUntil());
    }

    public function test_only_one_digest_per_channel_per_day(): void
    {
        $user = $this->client();
        $channel = $this->slackChannel($user);
        $row = ['channel_id' => $channel->id, 'user_id' => $user->id, 'kind' => 'digest', 'digest_date' => '2026-10-20'];

        NotificationDelivery::query()->create($row);
        // Outros tipos no mesmo dia continuam livres.
        NotificationDelivery::query()->create(['kind' => 'realtime', 'digest_date' => null] + $row);

        $this->expectException(QueryException::class);
        NotificationDelivery::query()->create($row);
    }

    public function test_deleting_the_user_removes_everything_about_the_alerts(): void
    {
        $user = $this->client();
        $profile = $this->profile($user, [], ['laravel']);
        $channel = $this->slackChannel($user);
        $case = $this->newsCase('Laravel RCE');
        $match = AlertMatch::query()->create([
            'profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now(),
        ]);
        $profile->domains()->create(['domain' => 'acme.com.br', 'verify_token' => 'abc']);

        $user->delete();

        $this->assertModelMissing($profile);
        $this->assertModelMissing($channel);
        $this->assertModelMissing($match);
        $this->assertDatabaseCount('monitored_domains', 0);
        $this->assertDatabaseCount('alert_profile_techs', 0);
        $this->assertModelExists($case);
    }

    public function test_allowed_levels_follow_the_minimum(): void
    {
        $this->assertSame(['critical'], (new AlertProfile(['min_criticity' => 'critical']))->allowedLevels());
        $this->assertSame(['critical', 'high'], (new AlertProfile(['min_criticity' => 'high']))->allowedLevels());
        $this->assertSame(AlertProfile::LEVELS, (new AlertProfile(['min_criticity' => 'low']))->allowedLevels());
    }

    public function test_alert_settings_have_defaults_and_admin_overrides(): void
    {
        $this->assertTrue(Settings::bool('alerts.enabled'));
        $this->assertSame(10, Settings::int('alerts.max_per_hour'));
        $this->assertSame('07:00', Settings::get('alerts.digest_default_time'));

        Settings::set('alerts.max_per_hour', 3);

        $this->assertSame(3, Settings::int('alerts.max_per_hour'));
    }
}
