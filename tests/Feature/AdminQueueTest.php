<?php

namespace Tests\Feature;

use App\Jobs\AdvancePentestRun;
use App\Jobs\EnrichArticle;
use App\Jobs\ExplainVulnerability;
use App\Jobs\MergeSimilarCases;
use App\Jobs\PrioritizePentestHypotheses;
use App\Jobs\RunCrawler;
use App\Models\Article;
use App\Models\NewsCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Fila (os cartões "Jobs na fila" e "Jobs falhos" do painel) e o quadro
 * "IA agora" de Coletas: que job é, sobre o quê, desde quando e por quê.
 */
class AdminQueueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function article(string $status, string $title = 'BeyondTrust zero-day exploited'): Article
    {
        return Article::query()->create([
            'url' => 'https://www.darkreading.com/'.Str::slug($title).'-'.uniqid(),
            'url_hash' => hash('sha256', uniqid('', true)),
            'source_host' => 'darkreading.com',
            'original_title' => $title,
            'content' => 'Now BeyondTrust joins the group.',
            'content_chars' => 32,
            'extraction' => 'full',
            'collected_at' => now()->subMinutes(10),
            'status' => $status,
        ]);
    }

    private function story(string $title): NewsCase
    {
        return NewsCase::query()->create(['title' => $title, 'summary' => 'Resumo.', 'first_at' => now(), 'latest_at' => now()]);
    }

    private function payload(object $job, int $timeout): string
    {
        return json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => get_class($job),
            'timeout' => $timeout,
            'data' => ['commandName' => get_class($job), 'command' => serialize($job)],
        ]);
    }

    private function queue(object $job, string $queue, int $timeout, ?int $reservedAt = null, int $availableAt = 0, int $attempts = 0): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => $queue, 'payload' => $this->payload($job, $timeout), 'attempts' => $attempts,
            'reserved_at' => $reservedAt, 'available_at' => $availableAt ?: now()->subMinute()->timestamp, 'created_at' => now()->subMinutes(5)->timestamp,
        ]);
    }

    public function test_the_dashboard_cards_open_the_queue_and_the_failed_jobs(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.queue').'"', false)
            ->assertSee('href="'.route('admin.queue', ['ver' => 'falhos']).'"', false);
    }

    public function test_the_queue_shows_what_runs_what_waits_and_why(): void
    {
        $article = $this->article('pending');
        $case = $this->story('PHP2Shell: two critical PHP RCE flaws');
        $this->queue(new EnrichArticle($article->id), 'enrich', 300, reservedAt: now()->subSeconds(90)->timestamp);
        $this->queue(new MergeSimilarCases([$case->id, 999999]), 'merge', 1800, availableAt: now()->addHours(2)->timestamp, attempts: 1);
        $this->queue(new RunCrawler, 'default', 3600);

        $this->actingAs($this->admin())->get(route('admin.queue'))
            ->assertOk()
            ->assertSeeInOrder(['Análise do artigo', 'rodando', 'BeyondTrust zero-day exploited · darkreading.com', 'Um worker está executando há', '(limite de 5 min)'])
            ->assertSee('Fusão de matérias')
            ->assertSee('PHP2Shell: two critical PHP RCE flaws')
            ->assertSee('matéria #999999 (já saiu do ar)')
            ->assertSee('Agendado para '.now()->addHours(2)->timezone('America/Sao_Paulo')->format('d/m H:i').' (daqui a')
            ->assertSee('esperando o limite da IA (hermes)')
            ->assertSee('Coleta das fontes')
            ->assertSee('Esperando um worker livre da fila `default`', false);
    }

    public function test_the_failed_jobs_show_the_reason_and_the_full_error(): void
    {
        $case = $this->story('Ahold Delhaize cyberattack');
        DB::table('failed_jobs')->insert([
            'uuid' => 'f0de1a12-f01b-4737-9e47-fe495066b07e', 'connection' => 'database', 'queue' => 'merge',
            'payload' => $this->payload(new MergeSimilarCases([$case->id]), 1800),
            'exception' => "RuntimeException: hermes HTTP 502: You've hit your session limit · resets 5am (UTC) in /var/www/html/app/Services/Hermes/Hermes.php:69\nStack trace:\n#0 /var/www/html/app/Jobs/MergeSimilarCases.php(80): ...",
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin())->get(route('admin.queue', ['ver' => 'falhos']))
            ->assertOk()
            ->assertSee('Fusão de matérias')
            ->assertSee('Ahold Delhaize cyberattack')
            ->assertSeeText("Motivo: RuntimeException: hermes HTTP 502: You've hit your session limit · resets 5am (UTC)")
            ->assertDontSee('Hermes.php:69</p>', false)
            ->assertSee('Stack trace:')
            ->assertSee('f0de1a12-f01b-4737-9e47-fe495066b07e');

        $this->get(route('admin.queue', ['ver' => 'tudo']))->assertSessionHasErrors('ver');
    }

    public function test_the_collections_page_shows_what_the_ai_is_doing_and_how_much_is_done(): void
    {
        $running = $this->article('pending', 'Fortinet patches FortiWeb flaw');
        $this->article('pending');
        $this->article('enriched');
        $this->article('enriched');
        $this->queue(new EnrichArticle($running->id), 'enrich', 300, reservedAt: now()->subSeconds(150)->timestamp);
        $this->queue(new EnrichArticle($running->id + 1), 'enrich', 300);
        $this->queue(new PrioritizePentestHypotheses(7), 'pentest-ai', 150);

        $this->actingAs($this->admin())->get(route('admin.collections'))
            ->assertOk()
            ->assertSee('IA agora')
            ->assertSee('50% <span class="text-ink-700">· 2 de 4</span>', false)
            ->assertSeeInOrder(['Análise do artigo', '50% do limite de 5 min', 'Fortinet patches FortiWeb flaw'])
            ->assertSee('Esperando a IA:')
            ->assertSee('Ordem das hipóteses do teste de segurança');
        $this->get(route('admin.queue'))->assertOk()->assertSee('Ordem das hipóteses do teste de segurança')->assertSee('chamada da IA do teste #7');
    }

    private function failedJob(string $uuid, string $title): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'merge',
            'payload' => $this->payload(new MergeSimilarCases([$this->story($title)->id]), 1800),
            'exception' => "RuntimeException: hermes HTTP 502: You've hit your session limit · resets 5am (UTC)",
            'failed_at' => now(),
        ]);
    }

    public function test_a_failed_job_goes_back_to_the_queue_or_is_deleted(): void
    {
        $this->failedJob('11111111-1111-4111-8111-111111111111', 'Ahold Delhaize cyberattack');
        $this->failedJob('22222222-2222-4222-8222-222222222222', 'PHP2Shell: two critical PHP RCE flaws');
        $this->actingAs($this->admin());

        $this->get(route('admin.queue', ['ver' => 'falhos']))
            ->assertSee('Tentar todos de novo')
            ->assertSee(route('admin.queue.retry', '11111111-1111-4111-8111-111111111111'))
            ->assertSee(route('admin.queue.forget', '22222222-2222-4222-8222-222222222222'));

        $this->from(route('admin.queue', ['ver' => 'falhos']))
            ->post(route('admin.queue.retry', '11111111-1111-4111-8111-111111111111'))
            ->assertRedirect(route('admin.queue', ['ver' => 'falhos']))
            ->assertSessionHas('status', 'Fusão de matérias voltou para a fila `merge`.');
        $this->assertSame(['22222222-2222-4222-8222-222222222222'], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertSame(['merge'], DB::table('jobs')->pluck('queue')->all());
        $this->assertSame(0, (int) DB::table('jobs')->value('attempts'));

        $this->delete(route('admin.queue.forget', '22222222-2222-4222-8222-222222222222'))->assertSessionHas('status', 'Fusão de matérias apagado da lista de falhos.');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // O mesmo job de novo (outra aba já tinha resolvido): aviso, sem erro.
        $this->delete(route('admin.queue.forget', '22222222-2222-4222-8222-222222222222'))->assertSessionHas('error');
        $this->post(route('admin.queue.retry-all'))->assertSessionHas('error', 'Não há job falho para tentar de novo.');
    }

    public function test_all_failed_jobs_go_back_to_the_queue_or_are_deleted_at_once(): void
    {
        $this->failedJob('11111111-1111-4111-8111-111111111111', 'Ahold Delhaize cyberattack');
        $this->failedJob('22222222-2222-4222-8222-222222222222', 'PHP2Shell: two critical PHP RCE flaws');
        $this->actingAs($this->admin());

        $this->post(route('admin.queue.retry-all'))->assertSessionHas('status', '2 job(s) voltaram para a fila.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('jobs')->count());

        $this->failedJob('33333333-3333-4333-8333-333333333333', 'Fortinet patches FortiWeb flaw');
        $this->delete(route('admin.queue.forget-all'))->assertSessionHas('status', '1 job(s) falho(s) apagado(s).');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_only_the_admin_retries_or_deletes(): void
    {
        $this->failedJob('11111111-1111-4111-8111-111111111111', 'Ahold Delhaize cyberattack');
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.queue.retry', '11111111-1111-4111-8111-111111111111'))->assertForbidden();
        $this->delete(route('admin.queue.forget-all'))->assertForbidden();
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_scheduled_job_is_advanced_to_the_next_turn_of_its_worker(): void
    {
        $id = $this->queue(new RunCrawler, 'default', 3600, availableAt: now()->addHours(3)->timestamp);
        $this->actingAs($this->admin());

        $this->get(route('admin.queue'))->assertSee(route('admin.queue.advance', $id));
        $this->from(route('admin.queue'))->post(route('admin.queue.advance', $id))
            ->assertRedirect(route('admin.queue'))
            ->assertSessionHas('status', 'Adiantado: Coleta das fontes roda na próxima volta do worker da fila `default`.');
        $this->assertLessThanOrEqual(now()->timestamp, (int) DB::table('jobs')->where('id', $id)->value('available_at'));
    }

    public function test_a_running_or_already_available_job_is_not_advanced(): void
    {
        $later = now()->addHours(3)->timestamp;
        $running = $this->queue(new RunCrawler, 'default', 3600, reservedAt: now()->subMinute()->timestamp, availableAt: $later);
        $waiting = $this->queue(new RunCrawler, 'default', 3600);
        $before = (int) DB::table('jobs')->where('id', $waiting)->value('available_at');
        $this->actingAs($this->admin());

        $this->get(route('admin.queue'))
            ->assertDontSee(route('admin.queue.advance', $running))
            ->assertDontSee(route('admin.queue.advance', $waiting))
            ->assertDontSee('Adiantar todos os agendados');

        foreach ([$running, $waiting, 999999] as $id) {
            $this->post(route('admin.queue.advance', $id))->assertSessionHas('error', 'Esse job já começou ou já não estava agendado.');
        }
        $this->assertSame($later, (int) DB::table('jobs')->where('id', $running)->value('available_at'));
        $this->assertSame($before, (int) DB::table('jobs')->where('id', $waiting)->value('available_at'));
    }

    public function test_all_scheduled_jobs_are_advanced_with_the_right_count(): void
    {
        $later = now()->addHours(2)->timestamp;
        $merge = $this->queue(new MergeSimilarCases([$this->story('Ahold Delhaize cyberattack')->id]), 'merge', 1800, availableAt: $later, attempts: 1);
        $explain = $this->queue(new ExplainVulnerability(1), 'vulnerability', 300, availableAt: $later);
        $pentest = $this->queue(new AdvancePentestRun(1, 2, 0), 'pentest', 300, availableAt: $later);
        $this->queue(new RunCrawler, 'default', 3600, reservedAt: now()->subMinute()->timestamp, availableAt: $later);
        $this->actingAs($this->admin());

        $this->get(route('admin.queue'))
            ->assertSee('Adiantar 2 job(s) agendado(s)?')
            ->assertSee('1 deles esperam o limite da IA')
            ->assertSee(route('admin.queue.advance', $merge))
            ->assertSee(route('admin.queue.advance', $explain))
            ->assertSee('espera a matéria assentar')
            ->assertDontSee(route('admin.queue.advance', $pentest))
            ->assertSee('o passo do teste de segurança tem hora marcada');

        $this->post(route('admin.queue.advance-all'))->assertSessionHas('status', '2 job(s) adiantado(s): rodam na próxima volta do worker de cada fila.');
        $this->assertSame(2, DB::table('jobs')->where('available_at', '<=', now()->timestamp)->count());
        $this->assertSame($later, (int) DB::table('jobs')->where('id', $pentest)->value('available_at'));

        // O passo do teste com hora marcada também não se adianta pelo botão de um job só.
        $this->post(route('admin.queue.advance', $pentest))->assertSessionHas('error');
        $this->post(route('admin.queue.advance-all'))->assertSessionHas('error', 'Não há job agendado para adiantar.');
    }

    public function test_only_the_admin_advances(): void
    {
        $later = now()->addHours(2)->timestamp;
        $id = $this->queue(new RunCrawler, 'default', 3600, availableAt: $later);

        $this->post(route('admin.queue.advance', $id))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create());
        $this->post(route('admin.queue.advance', $id))->assertForbidden();
        $this->post(route('admin.queue.advance-all'))->assertForbidden();
        $this->assertSame($later, (int) DB::table('jobs')->where('id', $id)->value('available_at'));
    }

    public function test_only_the_admin_opens_the_queue(): void
    {
        $this->get(route('admin.queue'))->assertRedirect(route('admin.login'));
        $this->actingAs(User::factory()->create())->get(route('admin.queue'))->assertForbidden();
    }
}
