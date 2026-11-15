<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\Company;
use App\Models\GithubFinding;
use App\Models\LookalikeAlert;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/** Red Team da E2e (travas do envio e dos links). */
class RedTeamE2eTest extends TestCase
{
    use RefreshDatabase;
    use VisibilityFixtures;

    private Company $acme;

    private User $bia;

    private Sector $ti;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->acme] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->bia = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bia, $this->ti, manager: true);
    }

    // S1, revisto na E5b1: o resumo de cada canal leva as fontes do escopo dele (Destinations::sourcesFor), não o que o
    // dono do canal criou. O do TI leva o do TI (saia ou não quem criou); o geral só com "copiar para os gerais" (D5); a
    // conversa pessoal de quem criou, nada da empresa.
    public function test_rt_s1_o_resumo_de_cada_canal_leva_o_do_escopo_dele(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $ana = $this->acme->members()->where('role', 'admin')->sole()->user;
        $ti = $this->companyChannel($this->bia, $this->acme, $this->ti, ['digest_scope' => 'all']);
        $general = $this->companyChannel($ana, $this->acme, null, ['digest_scope' => 'all']);
        $personal = NotificationChannel::query()->create([
            'user_id' => $this->bia->id, 'type' => 'telegram', 'method' => 'group', 'telegram_chat_id' => '-100', 'status' => 'active', 'digest_scope' => 'all',
        ]);
        $date = Carbon::parse('2026-10-21', 'America/Sao_Paulo');
        [$start] = app(DigestBuilder::class)->window($ti->refresh(), $date);
        $when = $start->copy()->addHours(2);

        $children = $this->childrenOf($this->profileOf($this->bia, $this->acme, $this->ti), $this->watchOf($this->bia, $this->acme, $this->ti), $this->monitorOf($this->bia, $this->acme, $this->ti));
        $match = $children[AlertMatch::class];
        $match->case->forceFill(['is_published' => true, 'latest_at' => $when, 'criticity' => 'critical'])->save();
        $match->forceFill(['matched_at' => $when])->save();
        $children[LookalikeAlert::class]->forceFill(['created_at' => $when])->save();
        $children[GithubFinding::class]->forceFill(['status' => 'sent', 'first_seen_at' => $when])->save();
        $build = fn (NotificationChannel $channel) => app(DigestBuilder::class)->build($channel->refresh(), $date);
        $has = fn (array $digest) => [$digest['mine'] !== [], $digest['radar'] !== [], $digest['github'] !== []];

        $this->assertSame([true, true, true], $has($build($ti)), 'Montagem do teste: o resumo do TI devia ter o do TI.');
        $this->assertSame([false, false, false], $has($build($general)), 'o geral não leva o do setor sem a cópia (D5)');
        $this->assertSame([false, false, false], $has($build($personal)), 'a conversa pessoal não leva nada da empresa');

        $this->ti->users()->detach($this->bia->id);
        $this->assertSame([true, true, true], $has($build($ti)), 'quem criou saiu do setor: o canal do setor segue recebendo');

        $this->ti->forceFill(['copy_to_general' => true])->save();
        $this->assertSame([true, true, true], $has($build($general)), 'com a cópia, o geral leva o do setor');
    }
}
