<?php

namespace Tests\Support\Company;

use App\Enums\CompanyRole;
use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\DomainFinding;
use App\Models\GithubFinding;
use App\Models\GithubMonitor;
use App\Models\LookalikeAlert;
use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\NewsCase;
use App\Models\NotificationChannel;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Empresas, setores e um item de cada modelo da visibilidade (DESENHO-5.2 §4), para os testes do Visibility e para as
 * varreduras de isolamento das PRs seguintes (E2c1 em diante). Empresa e setor sempre por forceFill, como o serviço.
 */
trait VisibilityFixtures
{
    /** @return array{Company, User} uma empresa ativa e a admin dela, que já entrou */
    protected function activeCompany(string $name, string $email): array
    {
        config(['secmon.enterprise.enabled' => true]);
        $company = app(Companies::class)->create($name, 'Admin '.$name, $email);
        app(Companies::class)->enable($company);
        $admin = User::query()->where('email', $email)->sole();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->companyMembership->forceFill(['joined_at' => now()])->save();

        return [$company, $admin];
    }

    protected function companyAccount(Company $company, CompanyRole $role = CompanyRole::Member, array $state = []): User
    {
        $user = User::factory()->create();
        $company->members()->forceCreate($state + ['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    }

    protected function companySector(Company $company, string $name): Sector
    {
        return Sector::query()->forceCreate(['company_id' => $company->id, 'name' => $name]);
    }

    /** Põe a conta no setor (como responsável, com `$manager`). */
    protected function joinSector(User $user, Sector $sector, bool $manager = false): void
    {
        $sector->users()->attach($user->id, ['is_manager' => $manager]);
    }

    /** Dono: a conta que cria; empresa e setor do item (nulos = conta pessoal). */
    protected function profileOf(User $owner, ?Company $company = null, ?Sector $sector = null): AlertProfile
    {
        return AlertProfile::query()->forceCreate($this->stamp($owner, $company, $sector) + ['status' => 'active', 'completed_at' => now()]);
    }

    protected function watchOf(User $owner, ?Company $company = null, ?Sector $sector = null): LookalikeWatch
    {
        $domain = MonitoredDomain::query()->create(['user_id' => $owner->id, 'domain' => Str::lower(Str::random(10)).'.com.br', 'verify_token' => Str::random(32)]);

        return LookalikeWatch::query()->forceCreate($this->stamp($owner, $company, $sector) + ['domain_id' => $domain->id, 'company_name' => 'Acme', 'brands' => ['Acme']]);
    }

    protected function monitorOf(User $owner, ?Company $company = null, ?Sector $sector = null): GithubMonitor
    {
        return GithubMonitor::query()->forceCreate($this->stamp($owner, $company, $sector) + ['status' => 'active']);
    }

    /**
     * Um item de cada filho, abaixo das raízes dadas.
     *
     * @return array<class-string, Model>
     */
    protected function childrenOf(AlertProfile $profile, LookalikeWatch $watch, GithubMonitor $monitor): array
    {
        $case = NewsCase::query()->create(['title' => 'Caso '.Str::random(6), 'fingerprint' => Str::random(32)]);
        $domain = $profile->domains()->create(['user_id' => $profile->user_id, 'domain' => Str::lower(Str::random(10)).'.com.br', 'verify_token' => Str::random(32)]);
        $lookalike = $watch->lookalikes()->create(['host' => Str::lower(Str::random(8)).'-login.com', 'first_seen_at' => now()]);
        $capture = LookalikeCapture::query()->create(['lookalike_id' => $lookalike->id, 'requested_at' => now()]);

        return [
            MonitoredDomain::class => $domain,
            AlertMatch::class => AlertMatch::query()->create(['profile_id' => $profile->id, 'case_id' => $case->id, 'kind' => 'new_case', 'matched_at' => now()]),
            DomainFinding::class => DomainFinding::query()->create(['domain_id' => $domain->id, 'label' => 'WordPress', 'evidence' => 'meta generator']),
            LookalikeDomain::class => $lookalike,
            LookalikeCapture::class => $capture,
            LookalikeAlert::class => LookalikeAlert::query()->create(['lookalike_id' => $lookalike->id, 'capture_id' => $capture->id, 'kind' => 'new', 'risk' => 'high']),
            GithubFinding::class => GithubFinding::query()->create([
                'monitor_id' => $monitor->id, 'fingerprint' => Str::random(40), 'kind' => 'key_leak', 'repo' => 'fulano/teste',
                'html_url' => 'https://github.com/fulano/teste', 'auto_level' => 'high', 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]),
        ];
    }

    /** Um canal de aviso da empresa (E5a): os gerais (`$sector` nulo) ou de um setor, conectado por `$by` (Slack, por webhook). */
    protected function companyChannel(User $by, Company $company, ?Sector $sector = null, array $attributes = []): NotificationChannel
    {
        return tap((new NotificationChannel(NotificationChannel::defaults() + $attributes + [
            'type' => 'slack', 'method' => 'webhook', 'status' => 'active', 'realtime' => true, 'digest' => false,
            'webhook_url' => 'https://hooks.slack.com/services/T000/B000/'.Str::random(24),
        ]))->forceFill($this->stamp($by, $company, $sector)))->save();
    }

    private function stamp(User $owner, ?Company $company, ?Sector $sector): array
    {
        return ['user_id' => $owner->id, 'company_id' => $company?->id, 'sector_id' => $sector?->id];
    }

    /** Relê quem cada conta é: o Visibility guarda o Viewer pela requisição, e o teste mudou o estado no meio dela. */
    protected function refreshVisibility(): void
    {
        app()->forgetInstance(Visibility::class);
    }

    protected function memberRow(User $user): CompanyMember
    {
        return $user->companyMembership()->firstOrFail();
    }
}
