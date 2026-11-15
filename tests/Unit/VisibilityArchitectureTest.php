<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Toda leitura do que se monitora passa pelo scope `visibleTo` (DESENHO-5.2 §4.4, opção B). O teste procura em `app/`
 * os jeitos de ler "o que é meu" por conta, que não enxergam empresa nem setor:
 *
 * - `relação`: as relações de quem criou (`$user->alertProfiles`, `->lookalikeWatches`, `->githubMonitor`,
 *   `->alertProfile`). Elas ficam no User para os limites por criador e a remoção da conta, nunca para mostrar;
 * - `dono`: a comparação `->user_id === ... ->id` de um `authorize()`; o certo é `Visibility::sees`/`manages`;
 * - `where`: `Raiz::query()` (ou `static::query()`) filtrando por `user_id`;
 * - `busca`: o Elasticsearch numa tela (a caixa do cliente nunca sai do ES por conta, §5).
 *
 * O que pode, para sempre, está em PERMITIDO (o sistema trabalhando ou o admin do Security Monitor), com o motivo. O que ainda vai
 * mudar está em PENDENTE, com a PR que muda: a contagem tem que bater (achar a mais derruba o teste; achar a menos
 * também, para a lista diminuir junto com o código).
 */
class VisibilityArchitectureTest extends TestCase
{
    private const PATTERNS = [
        // A relação de quem criou: chamada (->alertProfiles) ou pelo nome (with/load/has/whereHas('alertProfiles')).
        'relação' => '/->(alertProfiles|alertProfile|lookalikeWatches|githubMonitor)\b|\b(?:with|load|loadMissing|loadCount|withCount|has|doesntHave|whereHas)\(\s*\[?\s*[\'"](?:alertProfiles|alertProfile|lookalikeWatches|githubMonitor)\b/',
        // O dono comparado à mão, nos dois sentidos e com == / !== / != (Q1 do Red Team).
        'dono' => '/->user_id\s*(?:===|!==|==|!=)(?!=)|(?:===|!==|==|!=)\s*\$(?:this|request)->user\(\)->id\b|\$(?:this|request)->user\(\)->id\s*(?:===|!==|==|!=)[^;]*->user_id\b/',
        // Raiz ou filho filtrado por user_id: estático ou por query(), coluna qualificada, aspas duplas, array, firstWhere
        // e whereBelongsTo; a mesma coisa no DB::table das tabelas e no whereHas da raiz (Q1 do Red Team).
        'where' => '/\b(?:AlertProfile|LookalikeWatch|GithubMonitor|AlertMatch|LookalikeDomain|LookalikeCapture|LookalikeAlert|GithubFinding|DomainFinding|MonitoredDomain|OwnedDomain|NotificationChannel|static)::(?:query\(\)(?:(?!visibleTo)[^;])*?->)?(?:where|orWhere|firstWhere|whereIn)\(\s*\[?\s*[\'"](?:\w+\.)?user_id[\'"]|(?:^|[;{}])(?:(?!visibleTo)[^;{}])*?\bwhereBelongsTo\(/s',
        'tabela' => '/DB::table\(\s*[\'"](?:alert_profiles|lookalike_watches|github_monitors|alert_matches|lookalike_domains|lookalike_captures|lookalike_alerts|github_findings|domain_findings|monitored_domains|owned_domains|notification_channels)[\'"]\s*\)[^;]*?[\'"](?:\w+\.)?user_id[\'"]/s',
        'pelo pai' => '/\bwhereHas\(\s*[\'"](?:profile|watch|monitor|lookalike|domain)[\'"][^;]*?[\'"](?:\w+\.)?user_id[\'"]/s',
        'busca' => '/\b(CaseIndex|ProfileIndex)\b/',
    ];

    /** A `busca` só vale em controllers e form requests; os outros padrões, em todo `app/`. */
    private const ONLY_HTTP = ['busca'];

    /** As telas e as rotas também leem: nelas, a relação de quem criou (o resto já é pego no `app/`). */
    private const ALSO_SCANNED = ['resources/views', 'routes'];

    /** Prefixo de caminho => por que pode ler por conta. */
    private const PERMITIDO = [
        'app/Console/' => 'comandos do sistema (varrer, casar, resumo, poda): trabalham para todas as contas, ninguém vê',
        'app/Jobs/' => 'filas do sistema: o mesmo',
        'app/Observers/' => 'reação do banco (Radar começa quando a posse é comprovada), sem tela',
        'app/Services/Admin/' => 'admin do Security Monitor: vê e remove as contas de todos',
        'app/Http/Controllers/Admin/' => 'admin do Security Monitor',
        'app/Http/Requests/Admin/' => 'admin do Security Monitor',
        'resources/views/admin/' => 'admin do Security Monitor',
        'app/Models/User.php' => 'onde as relações de quem criou moram',
        // Arquivo a arquivo, não a pasta toda (Red Team da E2b2): um serviço novo da empresa que ler por conta derruba o teste.
        'app/Services/Company/CompanyHandover.php' => 'a poda (P7): passa para a admin o que a conta apagada criou, pelo user_id dela, de propósito',
        'app/Services/Domains/DomainOwner.php' => 'o dono da posse (conta ou empresa): o sistema provando um domínio, o dono vem do DomainOwnership, nunca da tela',
        'app/Services/Company/CompanyTwoFactor.php' => 'a 2FA da empresa: compara a conta da empresa (company_user), não o dono de um monitoramento',
        'app/Services/Pentest/' => 'testes de segurança: ficam por conta, fora da visibilidade de empresa (DESENHO-5.2 §2.4)',
        'app/Http/Requests/Alerts/Tests/' => 'testes de segurança: o mesmo',
        'app/Services/Prompts/PromptPreview.php' => 'Admin → Prompts: a prévia usa um monitoramento qualquer',
        'app/Http/Requests/Concerns/TargetsChannel.php' => 'a tela do app na conta pessoal: o canal dela (user_id, sem empresa); o da empresa vem do manageableBy (E5a2)',
        'app/Http/Requests/Alerts/Telegram/ShowTelegramRequest.php' => 'o grupo que esta conta acabou de conectar num escopo que ela configura (o authorize confere), para abrir a tela dele (E5a2)',
        'app/Services/Alerts/Webhook/WebhookOwnership.php' => 'a posse do webhook pessoal: a cópia tem de ser da conta dona do canal (no da empresa, vale a posse da empresa); sem tela',
    ];

    /**
     * Arquivo => [padrão => quantas], e a PR que troca. Diminua junto com o código; nunca aumente.
     *
     * @var array<string, array<string, int>>
     */
    private const PENDENTE = [
        // E5c: a anti-repetição do juiz passa a ser por empresa e setor.
        'app/Services/Alerts/AlertReviewer.php' => ['where' => 1],
    ];

    public function test_monitored_things_are_only_read_through_the_visibility_scope(): void
    {
        $found = $this->scan();
        ksort($found);
        $expected = self::PENDENTE;
        ksort($expected);

        $this->assertSame($expected, $found, "Leitura por conta fora do scope visibleTo. Use Visibility (sees/manages/visibleTo) ou, se for o sistema, ponha em PERMITIDO com o motivo. Achado agora:\n".var_export($found, true));
    }

    /**
     * Q1 do Red Team: os jeitos de escapar do padrão antigo. Cada trecho tem que ser pego; os do fim, não.
     */
    public function test_rt_q1_the_patterns_catch_the_known_escapes(): void
    {
        $escapes = [
            'AlertProfile::where(\'user_id\', $user->id)->get();',
            'AlertProfile::query()->where(\'alert_profiles.user_id\', $user->id)->get();',
            'LookalikeWatch::query()->where("user_id", $id)->first();',
            'AlertProfile::query()->where([\'user_id\' => $id])->get();',
            'AlertProfile::query()->firstWhere(\'user_id\', $id);',
            'GithubMonitor::query()->whereBelongsTo($user)->first();',
            '$user->load(\'alertProfiles\');',
            'User::query()->with([\'lookalikeWatches\'])->find($id);',
            'return $this->user()->id === $this->route(\'match\')->profile->user_id;',
            'return $finding->monitor->user_id == $this->user()->id;',
            'abort_if($watch->user_id !== $request->user()->id, 404);',
            'MonitoredDomain::query()->where(\'user_id\', $user->id)->pluck(\'domain\');',
            'GithubFinding::query()->where(\'user_id\', $id)->get();',
            // A posse (E3a1): a lista de domínios comprovados passa pelo Visibility, nunca pelo user_id.
            'OwnedDomain::query()->where(\'user_id\', $user->id)->whereNotNull(\'verified_at\')->get();',
            'DB::table(\'owned_domains\')->where(\'user_id\', $id)->pluck(\'domain\');',
            'DB::table(\'alert_profiles\')->where(\'user_id\', $id)->get();',
            // O canal (E5a): o da empresa se lê pelo visibleTo; o pessoal, pelas relações do User (só os pessoais).
            'NotificationChannel::query()->where(\'user_id\', $user->id)->get();',
            'DB::table(\'notification_channels\')->where(\'user_id\', $id)->pluck(\'id\');',
            'AlertMatch::query()->whereHas(\'profile\', fn ($q) => $q->where(\'user_id\', $id))->get();',
        ];

        foreach ($escapes as $code) {
            $this->assertNotSame([], $this->hitsIn('app/Http/Controllers/X.php', "<?php\n".$code), "Escapou: {$code}");
        }

        foreach ([
            'AlertProfile::query()->visibleTo($user)->where(\'status\', \'active\')->get();',
            'AlertProfile::query()->visibleTo($user)->where(\'user_id\', $user->id)->count();',
            'NotificationChannel::query()->visibleTo($user)->where(\'type\', \'webhook\')->get();',
            'return $query->visibleTo($channel->user)->whereBelongsTo($channel->user);',
        ] as $code) {
            $this->assertSame([], $this->hitsIn('app/Http/Controllers/X.php', "<?php\n".$code), "Falso positivo: {$code}");
        }
    }

    /** @return array<string, array<string, int>> */
    private function scan(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (['app', ...self::ALSO_SCANNED] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = substr($file->getPathname(), strlen($root) + 1);

                if ($this->allowed($path)) {
                    continue;
                }

                if ($hits = $this->hitsIn($path, (string) file_get_contents($file->getPathname()))) {
                    $found[$path] = $hits;
                }
            }
        }

        return $found;
    }

    /** @return array<string, int> padrão => quantas vezes, neste arquivo */
    private function hitsIn(string $path, string $code): array
    {
        $hits = [];

        foreach (self::PATTERNS as $name => $pattern) {
            if (in_array($name, self::ONLY_HTTP, true) && ! str_starts_with($path, 'app/Http/')) {
                continue;
            }

            if (! str_starts_with($path, 'app/') && $name !== 'relação') {
                continue;
            }

            if ($count = preg_match_all($pattern, $code)) {
                $hits[$name] = $count;
            }
        }

        return $hits;
    }

    private function allowed(string $path): bool
    {
        foreach (array_keys(self::PERMITIDO) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
