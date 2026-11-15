<?php

namespace App\Services\Github;

use App\Exceptions\ActionRefused;
use App\Jobs\ScanGithub;
use App\Models\Company;
use App\Models\GithubMonitor;
use App\Models\User;
use App\Services\Company\Companies;
use App\Services\Company\CompanyLimits;
use App\Services\Company\ItemSectors;
use App\Services\Company\Viewer;
use App\Services\Company\Visibility;
use App\Support\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A entrevista do monitoramento do GitHub, separada da entrevista de alertas:
 * as perguntas de cada passo, a validação das respostas e a ativação.
 *
 * Os nomes de chaves são só nomes: um item com cara de valor (token, senha,
 * texto longo e aleatório) é tirado antes de qualquer coisa, nunca é gravado
 * nem volta para a tela, e o cliente é avisado para trocar a chave.
 */
class GithubInterview
{
    /** Quanto tempo um segundo envio do passo 1 cai no rascunho do primeiro. */
    public const DOUBLE_SUBMIT_SECONDS = 15;

    public const LAST_STEP = 6;

    /** Título, explicação e subtítulo (no menu de passos) de cada passo. */
    public const STEPS = [
        1 => ['GitHub da empresa', 'Onde ficam os repositórios oficiais. Serve para separar o que é seu do que é de terceiros.', 'organizações e repositórios'],
        2 => ['Chaves e endereços internos', 'Nomes que só existem no código de vocês. Se aparecerem em código público, algo vazou.', 'o que nunca deveria sair'],
        3 => ['Marca e o que vigiar', 'Repositórios com o nome da marca podem ser clones, kits de phishing ou robôs.', 'marca e verificações'],
        4 => ['Adicional', 'Conte com as suas palavras o que mais preocupa. A IA lê o texto e transforma em buscas.', 'opcional'],
        5 => ['Como você quer ser avisado', 'O nível mínimo define o que passa para a segunda análise da IA e pode virar aviso.', 'nível mínimo'],
        6 => ['Revise e ative', 'Confira o que vai ser buscado uma vez por dia.', 'revisão'],
    ];

    /**
     * Perguntas por passo. `kind`: tags (vários itens), textarea, single
     * (cartões de nível) ou checks (cartões das verificações).
     */
    public const FIELDS = [
        'orgs' => ['step' => 1, 'kind' => 'tags', 'label' => 'Organizações e usuários oficiais no GitHub',
            'help' => 'Como aparecem no endereço: github.com/empresa. Até 5.', 'placeholder' => 'empresa'],
        'private_repos' => ['step' => 1, 'kind' => 'tags', 'label' => 'Repositórios privados, que nunca deveriam ficar públicos',
            'help' => 'O nome (api-pagamentos) ou dono/nome (empresa/api-pagamentos). Avisamos se um deles aparecer em público, na sua organização ou em outra conta. Até 15.', 'placeholder' => 'empresa/api-pagamentos'],
        'public_repos' => ['step' => 1, 'kind' => 'tags', 'label' => 'Repositórios públicos oficiais',
            'help' => 'Os que vocês publicam de propósito (SDKs, site, documentação), como dono/nome. Eles não viram aviso de imitação, mas uma chave vazada neles vira.', 'placeholder' => 'empresa/sdk-js'],
        'key_names' => ['step' => 2, 'kind' => 'tags', 'label' => 'Nomes de chaves e variáveis',
            'help' => 'Variáveis de ambiente e chaves de configuração que só existem no código de vocês, como FM_SITE_BR. Só o nome: nunca cole o valor. Até 20.', 'placeholder' => 'FM_SITE_BR'],
        'token_prefixes' => ['step' => 2, 'kind' => 'tags', 'label' => 'Prefixo dos tokens que vocês emitem',
            'help' => 'Se a sua API gera tokens com começo fixo (como fmk_live_), um token vazado é achado com certeza. Até 5.', 'placeholder' => 'fmk_live_'],
        'hostnames' => ['step' => 2, 'kind' => 'tags', 'label' => 'Endereços internos',
            'help' => 'Hosts que não deveriam aparecer fora da empresa: Jenkins, banco, API interna. Sem IP. Até 10.', 'placeholder' => 'jenkins.empresa.com.br'],
        'email_domains' => ['step' => 2, 'kind' => 'tags', 'label' => 'Domínios de e-mail corporativo',
            'help' => 'Procuramos e-mails @empresa junto de "password" ou "senha" em código público. Até 3.', 'placeholder' => 'empresa.com.br'],
        'brands' => ['step' => 3, 'kind' => 'tags', 'label' => 'Nomes da marca e dos produtos',
            'help' => 'Repositórios de terceiros com esses nomes no título ou na descrição viram achado. Até 5.', 'placeholder' => 'Empresa Pay'],
        'checks' => ['step' => 3, 'kind' => 'checks', 'label' => 'O que vigiar',
            'help' => 'Desligue o que não interessa. Tudo começa ligado.'],
        'extra' => ['step' => 4, 'kind' => 'textarea', 'label' => 'Adicional',
            'help' => 'Ex.: "O app mobile usa chaves que começam com FMK_. O repositório interno chama fm-core e o painel fica em painel.interno.empresa.com.br. Ex-funcionários já subiram código para contas pessoais."',
            'placeholder' => 'O que mais preocupa vocês no GitHub?'],
        'min_level' => ['step' => 5, 'kind' => 'single', 'label' => 'A partir de qual nível você quer ser avisado?',
            'help' => 'O que fica abaixo continua visível na página do GitHub, sem passar pela segunda análise.'],
    ];

    /** As opções do nível mínimo, no mesmo formato da entrevista de alertas. */
    public const LEVEL_OPTIONS = [
        'critical' => ['Somente Critical', 'Segredo com valor real exposto ou código privado publicado. Raro, e cada um exige ação na hora.'],
        'high' => ['High e acima', 'Inclui configuração interna exposta, e-mails com senha e kits de phishing com a sua marca.'],
        'medium' => ['Medium e acima', 'Mais volume: referências às suas chaves sem valor, cópias do seu código e robôs.'],
        'low' => ['Todos', 'Inclui menções à marca sem risco aparente. Combina melhor com o resumo diário.'],
    ];

    /** O aviso de quando um valor de chave foi colado no lugar do nome. */
    public static function secretWarning(int $removed): string
    {
        return ($removed === 1
            ? 'Um item parece o valor de uma chave, não o nome, e não foi salvo. Se for uma chave real, troque-a'
            : "{$removed} itens parecem valores de chave, não nomes, e não foram salvos. Se forem chaves reais, troque-as")
            .': copiar e colar já é uma exposição.';
    }

    private const LOGIN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/';

    private const REPO = '/^([A-Za-z0-9][A-Za-z0-9-]{0,38}\/)?[A-Za-z0-9._-]{1,100}$/';

    private const KEY = '/^[A-Za-z_][A-Za-z0-9_.\-]{2,79}$/';

    private const PREFIX = '/^[A-Za-z0-9][A-Za-z0-9_\-]{2,19}$/';

    private const HOST = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/';

    public function __construct(private ExtraFlow $extra, private Visibility $visibility, private Companies $companies, private CompanyLimits $limits) {}

    /**
     * O monitoramento da conta pessoal (um só); na primeira vez nasce vazio. Nada vem da entrevista de alertas: o
     * GitHub pode vigiar outra marca (ou outra empresa), então o cliente escreve tudo à mão. A conta de empresa não
     * ganha rascunho sozinha (DESENHO-5.2 §2.1): cria pelo passo 1, com o setor (startWith).
     */
    public function monitorFor(User $user): GithubMonitor
    {
        // Consulta nova (não a relação já carregada): o monitoramento pode ter nascido nesta mesma requisição.
        return GithubMonitor::query()->visibleTo($user)->oldest('id')->first()
            ?? GithubMonitor::query()->forceCreate($this->visibility->stampFor($user, null) + $this->blankAttributes());
    }

    /** Um monitoramento ainda não gravado, para a tela do passo 1 de um novo de empresa. */
    public function blank(): GithubMonitor
    {
        return new GithubMonitor($this->blankAttributes());
    }

    /**
     * A conta cria monitoramentos? A pessoal, enquanto não tem o seu; na empresa, quem administra e quem responde por
     * um setor, até o limite da empresa (P6, `limit_github_monitors`).
     */
    public function mayCreate(User $user): bool
    {
        $viewer = $this->visibility->viewer($user);

        return match ($viewer->kind) {
            Viewer::PERSONAL => ! GithubMonitor::query()->visibleTo($user)->exists(),
            Viewer::ADMIN, Viewer::SECTORS => ($viewer->kind === Viewer::ADMIN || $this->visibility->sectorChoices($user)->isNotEmpty())
                && GithubMonitor::query()->where('company_id', $viewer->companyId)->count() < $this->companies->limit(Company::query()->findOrFail($viewer->companyId), 'github_monitors'),
            default => false,
        };
    }

    /**
     * Pode começar um monitoramento novo (o passo 1 sem `?monitor=`): quem administra a empresa e quem responde por um
     * setor. O limite da empresa fica com o startWith, que avisa com a mensagem (e deixa o duplo clique cair no rascunho).
     */
    public function mayStart(User $user): bool
    {
        $viewer = $this->visibility->viewer($user);

        return $viewer->kind === Viewer::ADMIN || ($viewer->kind === Viewer::SECTORS && $this->visibility->sectorChoices($user)->isNotEmpty());
    }

    /**
     * Passo 1 de um monitoramento novo de empresa: cria com o setor (conferido pelo Visibility) e grava as respostas,
     * numa transação com a empresa travada (o limite sem corrida). Resposta inválida desfaz tudo.
     */
    public function startWith(User $user, ?int $sectorId, array $answers): GithubMonitor
    {
        return DB::transaction(function () use ($user, $sectorId, $answers) {
            // A empresa travada antes de conferir o setor: apagar o setor (Sectors::delete) trava a empresa também, então
            // um setor apagado no mesmo instante vira a mensagem, e não um erro de FK (Red Team da E2f).
            $company = Company::query()->lockForUpdate()->findOrFail($this->visibility->viewer($user)->companyId);
            ItemSectors::ensureSectorExists($sectorId);
            $stamp = $this->visibility->stampFor($user, $sectorId);
            $limit = $this->companies->limit($company, 'github_monitors');

            if (! $this->recentDraft($user, $stamp['sector_id'], $answers) && GithubMonitor::query()->where('company_id', $company->id)->count() >= $limit) {
                throw new ActionRefused("A empresa já tem {$limit} monitoramentos do GitHub, o máximo. Para criar outro, exclua um monitoramento. ".CompanyLimits::MORE);
            }

            $monitor = $this->recentDraft($user, $stamp['sector_id'], $answers) ?? GithubMonitor::query()->forceCreate($stamp + $this->blankAttributes());
            $this->limits->notify($company, 'github_monitors');
            $this->save($monitor, 1, $answers);

            return $monitor;
        });
    }

    /**
     * Duplo clique (ou dois envios juntos) no passo 1: o segundo pedido espera a trava da empresa e cai no rascunho que o
     * primeiro acabou de criar, igual (o mesmo autor, o mesmo setor e as mesmas respostas, há poucos segundos), em vez
     * de nascer outro (Red Team da E2d2). Um monitoramento diferente criado logo depois nasce normalmente.
     */
    private function recentDraft(User $user, ?int $sectorId, array $answers): ?GithubMonitor
    {
        $same = [];

        foreach (collect(self::FIELDS)->filter(fn ($f) => $f['step'] === 1) as $key => $field) {
            try {
                $same[$key] = $this->normalize($key, $answers[$key] ?? null);
            } catch (ValidationException) {
                return null;
            }
        }

        return GithubMonitor::query()->visibleTo($user)->where('user_id', $user->id)->whereNull('completed_at')
            ->where('sector_id', $sectorId)->where('created_at', '>=', now()->subSeconds(self::DOUBLE_SUBMIT_SECONDS))
            ->latest('id')->get()
            ->first(fn (GithubMonitor $draft) => collect($same)->every(fn ($value, $key) => $draft->{$key} == $value));
    }

    private function blankAttributes(): array
    {
        return ['status' => 'draft', 'checks' => array_keys(GithubMonitor::CHECKS), 'min_level' => 'high'];
    }

    /** As perguntas de um passo, com o valor atual de cada uma. */
    public function stepPage(User $user, GithubMonitor $monitor, int $step): array
    {
        $fields = collect(self::FIELDS)->filter(fn ($f) => $f['step'] === $step)->map(fn ($field, $key) => $field + [
            'key' => $key,
            'value' => match ($key) {
                'extra' => $monitor->extra_text,
                default => $monitor->{$key},
            },
            'max' => GithubMonitor::MAX[$key] ?? null,
        ]);

        return [
            'monitor' => $monitor,
            'step' => $step,
            'steps' => self::STEPS,
            'text' => self::STEPS[$step],
            'fields' => $fields,
            'plan' => $step === self::LAST_STEP ? $this->plan($monitor) : null,
            'codeSearch' => app(GithubClient::class)->hasToken(),
            // O setor de um monitoramento novo de empresa: o passo 1 escolhe; depois, "Trocar o setor" na página.
            'sectors' => $step === 1 && ! $monitor->exists ? $this->visibility->sectorChoices($user) : collect(),
            'sectorless' => $step === 1 && ! $monitor->exists && $this->visibility->canUseNoSector($user),
        ];
    }

    /**
     * Itens com cara de valor de chave, tirados das respostas antes de tudo
     * (nem a validação devolve o que foi digitado para a tela).
     *
     * @return array{answers: array, removed: int}
     */
    public static function withoutSecrets(array $answers): array
    {
        $removed = 0;

        foreach (['key_names', 'token_prefixes', 'orgs', 'private_repos', 'public_repos', 'hostnames', 'brands'] as $key) {
            if (! isset($answers[$key])) {
                continue;
            }

            $answers[$key] = array_values(array_filter((array) $answers[$key], function ($item) use (&$removed, $key) {
                $item = trim((string) $item);
                $secret = $item !== '' && ($key === 'key_names' ? SecretMasker::looksLikeSecretValue($item) : self::obviousSecret($item));
                $removed += $secret ? 1 : 0;

                return ! $secret;
            }));
        }

        if (isset($answers['extra']) && self::obviousSecret((string) $answers['extra'])) {
            $answers['extra'] = SecretMasker::mask((string) $answers['extra'])['text'];
            $removed++;
        }

        return ['answers' => $answers, 'removed' => $removed];
    }

    /** Fora dos nomes de chave, só o que é segredo sem dúvida (formato conhecido, chave privada, senha em URL). */
    private static function obviousSecret(string $text): bool
    {
        return collect(SecretMasker::mask($text)['secrets'])->contains(fn ($s) => in_array($s['source'], ['token', 'private_key', 'url'], true));
    }

    /** Valida e grava as respostas de um passo (já sem segredos). */
    public function save(GithubMonitor $monitor, int $step, array $answers): void
    {
        $values = [];
        $errors = [];

        foreach (collect(self::FIELDS)->filter(fn ($f) => $f['step'] === $step) as $key => $field) {
            try {
                $values[$key] = $this->normalize($key, $answers[$key] ?? null);
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (array_key_exists('extra', $values)) {
            $this->extra->changed($monitor, $values['extra']);
            unset($values['extra']);
        }

        $monitor->forceFill($values + ['step_reached' => max($monitor->step_reached, min($step + 1, self::LAST_STEP))])->save();
    }

    private function normalize(string $key, mixed $value): mixed
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(["answers.{$key}" => $message]);

        if ($key === 'extra') {
            $text = ExtraFlow::clean(is_string($value) ? $value : null);
            $max = (int) config('secmon.github.extra_max', 1000);

            if ($text !== null && mb_strlen(trim((string) $value)) > $max) {
                $fail("O texto pode ter até {$max} caracteres.");
            }

            return $text;
        }

        if ($key === 'min_level') {
            return in_array($value, GithubMonitor::LEVELS, true) ? $value : $fail('Escolha um nível.');
        }

        if ($key === 'checks') {
            $checks = array_values(array_intersect(array_keys(GithubMonitor::CHECKS), (array) $value));

            return $checks !== [] ? $checks : $fail('Deixe pelo menos uma verificação ligada.');
        }

        $items = [];

        foreach ((array) $value as $raw) {
            $item = $this->item($key, trim((string) $raw));

            if ($item === '') {
                continue;
            }

            if ($item === null) {
                $fail(self::invalid($key, trim((string) $raw)));
            }

            $items[mb_strtolower($item)] ??= $item;
        }

        $items = array_values($items);
        $max = GithubMonitor::MAX[$key];

        if (count($items) > $max) {
            $fail("Até {$max} itens.");
        }

        return $items;
    }

    /** Um item normalizado, '' se vazio, ou nulo se inválido. */
    private function item(string $key, string $raw): ?string
    {
        if ($raw === '') {
            return '';
        }

        // Endereços colados do navegador: https://github.com/empresa/repo → empresa/repo.
        $github = preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', rtrim($raw, '/'));

        return match ($key) {
            'orgs' => preg_match(self::LOGIN, $login = ltrim(explode('/', $github)[0], '@')) ? $login : null,
            'private_repos' => preg_match(self::REPO, $github = preg_replace('/\.git$/', '', $github)) ? $github : null,
            'public_repos' => preg_match(self::REPO, $github = preg_replace('/\.git$/', '', $github)) && str_contains($github, '/') ? $github : null,
            'key_names' => preg_match(self::KEY, $raw) ? $raw : null,
            'token_prefixes' => preg_match(self::PREFIX, $raw) ? $raw : null,
            'hostnames' => self::host($raw),
            'email_domains' => DomainName::normalize(ltrim($raw, '@')),
            'brands' => mb_strlen($raw) >= 3 && mb_strlen($raw) <= 40 && preg_match('/\p{L}.*\p{L}/u', $raw) ? $raw : null,
            default => null,
        };
    }

    private static function host(string $raw): ?string
    {
        $host = mb_strtolower(preg_replace(['#^[a-z][a-z0-9+.-]*://#i', '#[/:?\#].*$#'], '', $raw));

        return ! filter_var($host, FILTER_VALIDATE_IP) && preg_match(self::HOST, $host) ? $host : null;
    }

    private static function invalid(string $key, string $raw): string
    {
        $shown = '“'.mb_substr($raw, 0, 60).'”';

        return match ($key) {
            'orgs' => "{$shown} não é um nome de organização ou usuário do GitHub.",
            'private_repos' => "{$shown} não é um nome de repositório (use nome ou dono/nome).",
            'public_repos' => "{$shown}: use dono/nome, como empresa/sdk-js.",
            'key_names' => "{$shown} não parece o nome de uma chave: use letras, números, _ . ou -, sem espaços.",
            'token_prefixes' => "{$shown} não parece um prefixo: de 3 a 20 letras, números, _ ou -.",
            'hostnames' => "{$shown} não é um endereço válido (sem IP, sem porta).",
            'email_domains' => "{$shown} não é um domínio de e-mail válido.",
            default => "{$shown} não vale aqui (de 3 a 40 caracteres).",
        };
    }

    /** A primeira pergunta obrigatória sem resposta: sem nenhum termo próprio, não há o que buscar. */
    public function missing(GithubMonitor $monitor): ?string
    {
        $terms = count($monitor->orgs ?? []) + count($monitor->private_repos ?? []) + count($monitor->key_names ?? [])
            + count($monitor->token_prefixes ?? []) + count($monitor->hostnames ?? []) + count($monitor->email_domains ?? []) + count($monitor->brands ?? []);

        return $terms === 0 ? 'Informe pelo menos uma organização, um nome de chave ou a marca: sem isso não há o que buscar.' : null;
    }

    /** Ativa (primeira vez) e já agenda a primeira varredura. Devolve se acabou de ativar. */
    public function finish(GithubMonitor $monitor): bool
    {
        if ($message = $this->missing($monitor)) {
            throw new ActionRefused($message);
        }

        $first = ! $monitor->isCompleted();

        $monitor->forceFill([
            'completed_at' => $monitor->completed_at ?? now(),
            'status' => $monitor->isPaused() ? 'paused' : 'active',
            'step_reached' => self::LAST_STEP,
            'next_scan_at' => $first ? now() : $monitor->next_scan_at,
        ])->save();

        if ($first) {
            ScanGithub::dispatch($monitor->id);
        }

        return $first;
    }

    /**
     * O que a varredura vai buscar, para a revisão: quantas buscas de cada
     * tipo e alguns exemplos.
     *
     * @return array{total: int, code: int, groups: array<string, list<string>>, over: bool}
     */
    public function plan(GithubMonitor $monitor): array
    {
        $queries = GithubQueries::for($monitor);
        $code = collect($queries)->where('type', 'code')->count();

        return [
            'total' => count($queries),
            'code' => $code,
            'groups' => collect($queries)->groupBy('kind')->map(fn ($group) => $group->pluck('term')->unique()->values()->all())->all(),
            'over' => $code > (int) config('secmon.github.max_code_queries'),
        ];
    }
}
