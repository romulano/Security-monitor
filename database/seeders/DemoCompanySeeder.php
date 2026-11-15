<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Models\AlertProfile;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\LookalikeWatch;
use App\Models\MonitoredDomain;
use App\Models\OwnedDomain;
use App\Models\Sector;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\Sectors;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dados de demonstração do plano empresarial (D13), SÓ PARA O LABORATÓRIO: nunca roda em produção (só com
 * APP_ENV local ou testing) e não está no DatabaseSeeder, então nenhum deploy o chama.
 *
 * Como rodar no laboratório (banco descartável, ENTERPRISE_ENABLED=true), depois do `migrate`:
 *     php artisan db:seed --class=DemoCompanySeeder --force
 * ou, num script de seed das capturas (lab/visual-*), `app(DemoCompanySeeder::class)->run();` depois do bootstrap.
 *
 * O que cria: a empresa "Acme Demonstração" (ligada), 3 setores e 10 contas, todas com a senha DEMO_PASSWORD e o
 * e-mail confirmado, em @demo-acme.example (domínio reservado, nunca resolve):
 * - ana (admin, responde por todos os setores, D3);
 * - bruno (responsável do TI) e carla (responsável do Financeiro);
 * - diego e elisa (TI), fabio e elisa (Financeiro), gabi e heitor (Marketing), iris (sem setor), membros;
 * - joao (membro do TI, desativado: removido pela admin, §4.6).
 * Cada conta ativa tem um perfil de alerta; a admin e as duas responsáveis têm também um Radar (domínio comprovado)
 * e um monitoramento do GitHub. Tudo é da empresa (`company_id`): o da admin sem setor, o das outras no 1º setor dela
 * (sem setor, se não tem nenhum); a posse do domínio também, provada com o token da empresa (DESENHO-5.3 §2.3). As
 * próximas varreduras ficam para daqui a um ano: nada sai para a rede sozinho.
 */
class DemoCompanySeeder extends Seeder
{
    public const COMPANY = 'Acme Demonstração';

    public const DEMO_PASSWORD = 'senha-de-demo-123';

    public const DOMAIN = 'demo-acme.example';

    /** nome, login (antes do @), papel, setores, responsável pelos setores, desativada */
    private const ACCOUNTS = [
        ['Bruno Souza', 'bruno', CompanyRole::SectorManager, ['TI'], true, false],
        ['Carla Dias', 'carla', CompanyRole::SectorManager, ['Financeiro'], true, false],
        ['Diego Martins', 'diego', CompanyRole::Member, ['TI'], false, false],
        ['Elisa Rocha', 'elisa', CompanyRole::Member, ['TI', 'Financeiro'], false, false],
        ['Fábio Lima', 'fabio', CompanyRole::Member, ['Financeiro'], false, false],
        ['Gabi Nunes', 'gabi', CompanyRole::Member, ['Marketing'], false, false],
        ['Heitor Alves', 'heitor', CompanyRole::Member, ['Marketing'], false, false],
        ['Íris Prado', 'iris', CompanyRole::Member, [], false, false],
        ['João Reis', 'joao', CompanyRole::Member, ['TI'], false, true],
    ];

    public function run(): void
    {
        // Lista branca, não negra: um servidor com APP_ENV "prod" ou "staging" ganharia 10 contas com a senha pública (P1).
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoCompanySeeder é só do laboratório (APP_ENV local ou testing): não roda em produção.');
        }

        if (Company::query()->where('name', self::COMPANY)->exists()) {
            throw new RuntimeException('A empresa de demonstração já existe neste banco.');
        }

        DB::transaction(function () {
            $companies = app(Companies::class);
            $company = $companies->create(self::COMPANY, 'Ana Lima', 'ana@'.self::DOMAIN);
            $companies->enable($company);
            $ana = User::query()->where('email', 'ana@'.self::DOMAIN)->sole();
            $ana->forceFill(['password' => self::DEMO_PASSWORD, 'email_verified_at' => now()])->save();
            $ana->companyMembership->forceFill(['joined_at' => now()->subDays(10)])->save();

            $sectors = app(Sectors::class);
            $byName = collect(['TI', 'Financeiro', 'Marketing'])->mapWithKeys(fn ($name) => [$name => $sectors->create($ana, $name)]);
            $this->monitors($company, $ana, null, self::DOMAIN);

            foreach (self::ACCOUNTS as [$name, $login, $role, $in, $manager, $off]) {
                $user = User::query()->forceCreate(['name' => $name, 'email' => $login.'@'.self::DOMAIN, 'password' => self::DEMO_PASSWORD, 'is_admin' => false, 'email_verified_at' => now()]);
                $member = $company->members()->forceCreate(['user_id' => $user->id, 'role' => $role, 'invited_at' => now()->subDays(9), 'joined_at' => now()->subDays(8)]);

                foreach ($in as $sector) {
                    $sectors->addMember($ana, $byName[$sector], $member->id);
                    $manager && $sectors->setManager($ana, $byName[$sector], $member, true);
                }

                if ($off) {
                    // Removida pela admin (§4.6): fica nos setores, sem acesso, e a poda a apaga em 30 dias.
                    $member->forceFill(['deactivated_at' => now()->subDay()])->save();

                    continue;
                }

                $sector = $in === [] ? null : $byName[$in[0]];
                $manager ? $this->monitors($company, $user, $sector, strtolower($in[0]).'.'.self::DOMAIN) : $this->profile($company, $user, $sector);
            }
        });
    }

    /** Um perfil de alerta, um Radar do domínio (comprovado na empresa) e um monitoramento do GitHub, no setor. */
    private function monitors(Company $company, User $user, ?Sector $sector, string $domain): void
    {
        $this->profile($company, $user, $sector);
        $stamp = ['user_id' => $user->id, 'company_id' => $company->id, 'sector_id' => $sector?->id];
        $token = $company->fresh()->domain_token;

        OwnedDomain::query()->forceCreate($stamp + ['domain' => $domain, 'source' => 'radar', 'proof_token' => $token, 'verified_at' => now(), 'checked_at' => now(), 'check_status' => 'present']);
        $row = MonitoredDomain::query()->create(['user_id' => $user->id, 'domain' => $domain, 'verify_token' => $token, 'consent_at' => now(), 'consent_version' => 'v3', 'verified_at' => now()]);
        LookalikeWatch::query()->forceCreate($stamp + [
            'domain_id' => $row->id, 'company_name' => self::COMPANY, 'brands' => ['Acme Demo'],
            'step_reached' => 3, 'completed_at' => now(), 'status' => 'active', 'scan_status' => 'done',
            'last_scan_at' => now()->subDay(), 'next_scan_at' => now()->addYear(),
        ]);

        GithubMonitor::query()->forceCreate($stamp + [
            'status' => 'active', 'step_reached' => 6, 'completed_at' => now(),
            'orgs' => ['acme-demo'], 'brands' => ['Acme Demo'], 'email_domains' => [$domain],
            'checks' => array_keys(GithubMonitor::CHECKS), 'min_level' => 'high', 'next_scan_at' => now()->addYear(),
        ]);
    }

    private function profile(Company $company, User $user, ?Sector $sector): void
    {
        AlertProfile::query()->forceCreate([
            'user_id' => $user->id, 'company_id' => $company->id, 'sector_id' => $sector?->id, 'status' => 'active', 'company_name' => self::COMPANY, 'brands' => ['Acme Demo'],
            'min_criticity' => 'high', 'step_reached' => 6, 'completed_at' => now(),
        ]);
    }
}
