<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Jobs\SendEmail;
use App\Mail\CompanyDomainNotice;
use App\Models\Company;
use App\Models\EmailMessage;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Company\CompanyDomainNotices;
use App\Services\Domains\DomainOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Alerts\FakeDnsResolver;
use Tests\Support\Company\VisibilityFixtures;
use Tests\TestCase;

/**
 * Red Team da E3d. `rt_*`: passava no 850b79a e o patch fecha; `evidencia_*`: o que resistiu.
 *
 * F1: o "o que parou" do e-mail de posse caída conta toda cópia da empresa com o nome ou um subdomínio dele, de
 * qualquer setor, também a que continua comprovada por uma linha própria. O responsável do TI fica sabendo quantos
 * Radares e alertas o RH tem sob o domínio, e o número diz que pararam quando não pararam. Conta só o que perdeu a
 * prova agora e é do setor do domínio (o domínio sem setor: todos; o webhook, que é da conta, sempre).
 */
class RedTeamE3dTest extends TestCase
{
    use RefreshDatabase, VisibilityFixtures;

    private FakeDnsResolver $dns;

    private Company $acme;

    private User $ana;

    private Sector $ti;

    private Sector $rh;

    private User $bruno;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(DnsResolver::class, $this->dns = new FakeDnsResolver);
        [$this->acme, $this->ana] = $this->activeCompany('Acme', 'ana@acme.com');
        $this->ti = $this->companySector($this->acme, 'TI');
        $this->rh = $this->companySector($this->acme, 'RH');
        $this->bruno = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->bruno, $this->ti, manager: true);
        $this->carla = $this->companyAccount($this->acme, CompanyRole::SectorManager);
        $this->joinSector($this->carla, $this->rh, manager: true);
    }

    private function companyDomain(string $domain, ?Sector $sector): OwnedDomain
    {
        return OwnedDomain::query()->forceCreate([
            'user_id' => $this->ana->id, 'company_id' => $this->acme->id, 'sector_id' => $sector?->id, 'domain' => $domain,
            'source' => 'radar', 'proof_token' => $this->acme->domain_token, 'verified_at' => now()->subMonth(), 'checked_at' => now()->subDay(),
        ]);
    }

    private function radar(User $owner, Sector $sector, string $domain): void
    {
        $this->watchOf($owner, $this->acme, $sector)->domain->forceFill(['domain' => $domain, 'verify_token' => $this->acme->domain_token, 'verified_at' => now()])->save();
    }

    /** @return list<CompanyDomainNotice> os e-mails de domínio na fila, na ordem */
    private function mails(string $kind): array
    {
        return Queue::pushed(SendEmail::class)->map(fn (SendEmail $job) => $job->mail)
            ->filter(fn ($mail) => $mail instanceof CompanyDomainNotice && $mail->kind === $kind)->values()->all();
    }

    public function test_rt_f1_what_stopped_counts_only_what_lost_the_proof_in_the_domain_sector(): void
    {
        $owned = $this->companyDomain('acme.com.br', $this->ti);
        $this->radar($this->bruno, $this->ti, 'acme.com.br');
        // O RH tem um Radar em loja.acme.com.br, comprovado pela linha própria do RH: não depende do domínio do TI.
        $this->companyDomain('loja.acme.com.br', $this->rh);
        $this->radar($this->carla, $this->rh, 'loja.acme.com.br');
        $this->dns->set('acme.com.br', 'txt', [])->set('loja.acme.com.br', 'txt', [DomainOwnership::PREFIX.$this->acme->domain_token]);

        app(DomainOwnership::class)->recheck($owned);
        $this->travel(8)->days();
        app(DomainOwnership::class)->recheck($owned->refresh());
        $this->assertNull($owned->refresh()->verified_at);

        $lost = $this->mails('lost');
        $this->assertSame(['ana@acme.com', $this->bruno->email], Queue::pushed(SendEmail::class)->filter(fn (SendEmail $job) => $job->mail instanceof CompanyDomainNotice && $job->mail->kind === 'lost')
            ->map(fn (SendEmail $job) => EmailMessage::query()->find($job->messageId)->to_address)->values()->all());
        // No 850b79a: ['2 Radares'] (o do RH contado como parado, e mostrado ao TI).
        $this->assertSame(['1 Radar'], $lost[0]->stopped);
    }

    public function test_evidencia_only_who_configures_gets_the_company_txt(): void
    {
        // Responsável marcado no setor mas com papel de membro, responsável desativado, admin desativada: nada.
        $falso = $this->companyAccount($this->acme);
        $this->joinSector($falso, $this->ti, manager: true);
        $saiu = $this->companyAccount($this->acme, CompanyRole::SectorManager, ['deactivated_at' => now()]);
        $this->joinSector($saiu, $this->ti, manager: true);
        $owned = $this->companyDomain('ti.acme.com.br', $this->ti);
        $this->dns->set('ti.acme.com.br', 'txt', []);

        app(DomainOwnership::class)->recheck($owned);
        $to = EmailMessage::query()->where('kind', 'empresa-dominio')->orderBy('id')->pluck('to_address')->all();
        $this->assertSame(['ana@acme.com', $this->bruno->email], $to);
        $this->assertStringContainsString('ou o DNS dele não respondeu', $this->mails('unseen')[0]->render());

        // DNS mudo no dia seguinte, e o TXT de volta e sumindo de novo: um aviso por episódio.
        $this->travel(1)->days();
        $this->dns->failing['ti.acme.com.br'] = 5;
        app(DomainOwnership::class)->recheck($owned->refresh());
        $this->assertSame(2, EmailMessage::query()->where('kind', 'empresa-dominio')->count());

        // A empresa desligada (a chave dela) não recebe: o marco do limite também não é gasto.
        $this->acme->forceFill(['enterprise_enabled_at' => null])->save();
        app(CompanyDomainNotices::class)->limit($this->acme->refresh(), 'domains', 15, 15);
        $this->assertSame(0, DB::table('company_limit_notices')->count());
    }
}
