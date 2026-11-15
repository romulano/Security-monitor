<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertProfile;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Models\TechCatalog;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\OwnershipHelpers;

/** Fábricas pequenas para os testes de alertas (catálogo e perguntas vêm da migration). */
trait AlertsTestHelpers
{
    use OwnershipHelpers;

    protected function client(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['is_admin' => false]);
    }

    protected function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** Perfil ativo com as tecnologias do catálogo indicadas por slug. */
    protected function profile(?User $user = null, array $attributes = [], array $techs = [], array $terms = []): AlertProfile
    {
        $profile = AlertProfile::query()->create($attributes + [
            'user_id' => ($user ?? $this->client())->id,
            'status' => 'active',
            'company_name' => 'Acme Pagamentos',
            'brands' => [],
            'min_criticity' => 'high',
            'completed_at' => now(),
        ]);

        foreach ($techs as $slug) {
            $profile->techs()->attach(TechCatalog::query()->where('slug', $slug)->value('id'), [
                'source' => 'interview',
                'created_at' => now(),
            ]);
        }

        foreach ($terms as $term) {
            $profile->terms()->create(['value' => $term]);
        }

        return $profile->refresh();
    }

    /** Caso com indicadores no mesmo formato que o CaseWriter grava. */
    protected function newsCase(string $title, string $criticity = 'high', array $indicators = [], array $attributes = []): NewsCase
    {
        $groups = [];

        foreach ($indicators as $field => $values) {
            $groups[] = ['field' => $field, 'label' => $field, 'values' => (array) $values];
        }

        $case = NewsCase::query()->create($attributes + [
            'title' => $title,
            'criticity' => $criticity,
            'summary' => $attributes['summary'] ?? 'Resumo de teste.',
            'indicators' => $groups,
            'first_at' => now(),
            'latest_at' => now(),
            'source_count' => 1,
            'update_count' => 1,
        ]);

        $this->article($case);

        return $case;
    }

    protected function article(NewsCase $case, array $attributes = []): Article
    {
        $url = 'https://example.com/'.Str::random(12);

        return Article::query()->create($attributes + [
            'case_id' => $case->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'source_host' => 'example.com',
            'original_title' => $case->title,
            'title' => $case->title,
            'status' => 'enriched',
            'collected_at' => now(),
            'published_at' => now(),
        ]);
    }

    protected function slackChannel(User $user, array $attributes = []): NotificationChannel
    {
        return NotificationChannel::query()->create($attributes + [
            'user_id' => $user->id,
            'type' => 'slack',
            'method' => 'webhook',
            'webhook_url' => 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnopqrstuvwx',
            'status' => 'active',
            'realtime' => true,
            'digest' => true,
            'digest_time' => '07:00',
            'digest_days' => [1, 2, 3, 4, 5, 6, 7],
            'timezone' => 'America/Sao_Paulo',
        ]);
    }
}
