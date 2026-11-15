<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Acessos: o robô da busca de 2026-11-01 (sem JavaScript, só buscas com
 * várias facetas, 1 a 4 acessos por IP) conta como provável robô nos cartões e
 * nos filtros, sem nenhum dado novo gravado sobre ninguém. Desde a regra do 1 s
 * (AccessReport::HUMAN_MIN_MS), quem nunca manda o tempo é provável robô mesmo
 * sem as facetas.
 */
class LikelyRobotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Teste sem RefreshDatabase que abre uma página grava fora da transação e o registro fica (como no PageViewsTest).
        PageView::query()->delete();
    }

    private function pageView(string $ip, string $path, ?int $duration = null, bool $bot = false): void
    {
        PageView::query()->create([
            'token' => (string) Str::uuid(), 'ip' => $ip, 'country' => 'SG', 'user_agent' => 'Mozilla/5.0 Chrome/119.0.0.0',
            'is_bot' => $bot, 'path' => $path, 'status' => 200, 'duration_ms' => $duration, 'created_at' => now(),
        ]);
    }

    public function test_who_never_sends_the_time_and_only_opens_multi_facet_searches_is_a_likely_robot(): void
    {
        $facets = '/?q=ttps_attack%3AT1190%20criticity%3Acritical%20victim_sectors%3Aretail';
        // O robô: duas buscas de 3 facetas, sem o tempo da página (não roda JavaScript).
        $this->pageView('2a12:f540::1', $facets);
        $this->pageView('2a12:f540::1', '/?q=criticity:high+victim_sectors:retail+countries_code:BD&page=2');
        // Gente: a página inicial com o tempo, e uma busca de 3 facetas cujo tempo não chegou.
        $this->pageView('203.0.113.7', '/', 41000);
        $this->pageView('203.0.113.7', $facets);
        // Sem o tempo, numa busca de 2 facetas: nunca ficou 1 s numa página, então também é provável robô.
        $this->pageView('198.51.100.20', '/?q=criticity%3Ahigh%20victim_sectors%3Aretail');
        // Robô declarado no navegador.
        $this->pageView('198.51.100.9', '/', bot: true);

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $all = $this->get(route('admin.accesses'))->assertOk()->assertSee('provável robô');
        $this->assertSame(4, (int) $all->viewData('totals')->bots, 'as 3 dos prováveis robôs e a do declarado');
        $byIp = $all->viewData('byIp')->keyBy('ip');
        $this->assertTrue($byIp['2a12:f540::1']->likely_bot);
        $this->assertFalse($byIp['203.0.113.7']->likely_bot);
        $this->assertTrue($byIp['198.51.100.20']->likely_bot);

        $people = $this->get(route('admin.accesses', ['robos' => 'sem']))->assertOk();
        // A busca de 3 facetas do 203.0.113.7, sem o tempo, conta: a regra é por IP no período, não por linha.
        $this->assertSame(['203.0.113.7'], $people->viewData('byIp')->pluck('ip')->all());
        $this->assertSame(2, $people->viewData('recent')->total());
        $this->assertSame(0, (int) $people->viewData('totals')->bots);

        $robots = $this->get(route('admin.accesses', ['robos' => 'so']))->assertOk();
        $this->assertEqualsCanonicalizing(['2a12:f540::1', '198.51.100.9', '198.51.100.20'], $robots->viewData('byIp')->pluck('ip')->all());
        $this->assertSame(4, $robots->viewData('recent')->total());

        // Nada foi apagado.
        $this->assertSame(6, PageView::query()->count());
    }
}
