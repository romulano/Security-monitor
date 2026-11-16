<?php

namespace App\Services\Alerts;

use App\Jobs\DiscoverDomainStack;
use App\Jobs\ExpandCustomAnswer;
use App\Models\AlertProfile;
use App\Models\CustomAnswerTerm;
use App\Models\InterviewQuestion;
use App\Models\MonitoredDomain;
use App\Models\TechCatalog;
use App\Models\User;
use App\Services\Access\Visibility;
use App\Services\Alerts\Discovery\DnsSuggester;
use App\Services\Domains\DomainOwner;
use App\Services\Domains\DomainOwnership;
use App\Support\DomainName;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A entrevista: perguntas de cada passo, validação das respostas e a
 * tradução delas para o perfil (colunas, tecnologias, termos e queries).
 *
 * As respostas cruas ficam em alert_profile_answers para a entrevista
 * reabrir como estava; o perfil é sempre derivado delas em sync().
 */
class InterviewService
{
    public const LAST_STEP = 7;

    /** Sufixo da resposta escrita de uma pergunta de escolha: `sectors_outros`. */
    public const OTHER = '_outros';

    private const MAX_OTHER = 10;

    /** Valor de quem ainda não respondeu. */
    public const DEFAULTS = [
        'min_criticity' => 'high',
        'language' => 'pt',
        'brand_mentions' => true,
        'sector_news' => false,
        'case_updates' => true,
    ];

    /** Título e explicação de cada passo, no topo da tela. */
    public const STEP_TEXT = [
        1 => ['Sobre a empresa', 'Usamos para reconhecer quando a sua empresa aparece numa notícia e para entender o seu contexto.'],
        2 => ['Desenvolvimento', 'Falhas em linguagens, frameworks e pacotes são boa parte das notícias. Marque o que roda em produção.'],
        3 => ['Infraestrutura', 'Onde as aplicações rodam. Na dúvida, marque o que tiver certeza e deixe o resto para a descoberta pelo domínio.'],
        4 => ['Rede, site e ferramentas', 'Equipamentos de borda, o site e os serviços em nuvem que a empresa usa no dia a dia.'],
        5 => ['Domínio e descoberta automática', 'Com a sua autorização, olhamos só o que qualquer navegador vê no seu site e adicionamos ao perfil as tecnologias que reconhecermos. Dá para remover qualquer uma.'],
        6 => ['Como você quer ser avisado', 'Isso define o que chega na hora. O resumo diário é configurado depois, junto com o Slack.'],
        7 => ['Revise antes de ativar', 'A prévia abaixo roda o seu perfil contra os casos reais dos últimos 30 dias.'],
    ];

    /**
     * Categorias em que a versão muda o que uma falha atinge (PHP 8.2, Ubuntu
     * 22.04, FortiOS 7.2). Nuvem, e-mail e serviços de terceiros são SaaS: o
     * fornecedor atualiza, e o cliente nem sabe a versão.
     */
    public const VERSIONED = ['language', 'framework', 'devops', 'os', 'webserver', 'database', 'virtualization', 'network', 'cms', 'tool', 'endpoint'];

    /** Serviços nessas categorias que também são SaaS ou se atualizam sozinhos: não há versão para informar. */
    public const UNVERSIONED = [
        'shopify', 'wix', 'webflow',
        'github', 'bitbucket', 'azure-devops', 'github-actions', 'circleci',
        'slack', 'teams', 'zoom', 'salesforce', 'servicenow', 'zendesk', 'docusign',
        'cloudflare', 'akamai', 'fastly', 'cloudfront',
    ];

    /** Para onde vai a tecnologia que nenhuma pergunta oferece (Registro.br): escrita em "Ferramentas da empresa". */
    private const FALLBACK_QUESTION = 'tools';

    /** @var array<string, Collection<int, TechCatalog>> */
    private array $catalogCache = [];

    public function __construct(
        private ProfileCompiler $compiler,
        private ProfileIndex $index,
        private ProfilePreview $preview,
        private Rematcher $rematcher,
        private CatalogMatcher $catalogMatcher,
        private DnsSuggester $dnsSuggester,
        private DomainOwnership $ownership,
        private Visibility $visibility,
    ) {}

    /** Quanto tempo um segundo envio igual do passo 1 de um alerta novo conta como o mesmo (duplo clique). */
    private const DOUBLE_SUBMIT_SECONDS = 15;

    /** Respostas que não passam de um alerta para outro: cada um é de um site. */
    private const NOT_COPIED = ['alert_name', 'has_domain', 'domain'];

    /**
     * O alerta que a URL indica (`?alerta=`), sempre um que a conta configura (404 se
     * não for: a entrevista é configuração, R14). Sem indicação, o primeiro que ela
     * configura; a conta pessoal ganha um rascunho se não houver nenhum. A conta de
     * empresa não ganha rascunho sozinha (DESENHO-5.2 §2.1): cria pelo "Novo alerta".
     */
    public function profileFor(User $user, ?int $id = null): AlertProfile
    {
        $managed = AlertProfile::query()->manageableBy($user);

        if ($id) {
            return $managed->findOrFail($id);
        }

        return $managed->oldest('id')->first() ?? AlertProfile::query()->create(['user_id' => $user->id, 'status' => 'draft']);
    }

    /**
     * Passo 1 de um alerta novo (como o GitHub): nasce com as respostas, numa transação — resposta inválida não deixa
     * rascunho. Dois envios juntos (duplo clique) caem no mesmo rascunho.
     */
    public function startWith(User $user, array $answers, array $extra = []): AlertProfile
    {
        return DB::transaction(function () use ($user, $answers, $extra) {
            $stamp = $this->visibility->stampFor($user);
            $profile = $this->recentDraft($user, $answers);

            $profile ??= AlertProfile::query()->forceCreate($stamp + ['status' => 'draft']);
            $this->save($profile, 1, $answers, $extra);

            return $profile;
        });
    }

    /** O rascunho igual (mesmo autor, setor, nome e empresa) que o mesmo passo 1 criou há poucos segundos. */
    private function recentDraft(User $user, array $answers): ?AlertProfile
    {
        $same = fn (string $key) => trim((string) (is_scalar($answers[$key] ?? null) ? $answers[$key] : ''));

        return AlertProfile::query()->visibleTo($user)->where('user_id', $user->id)->whereNull('completed_at')
            ->where('created_at', '>=', now()->subSeconds(self::DOUBLE_SUBMIT_SECONDS))
            ->with('answers')->latest('id')->get()
            ->first(fn (AlertProfile $draft) => collect(['alert_name', 'company_name'])
                ->every(fn ($key) => trim((string) (is_scalar($draft->answerMap()[$key] ?? null) ? $draft->answerMap()[$key] : '')) === $same($key)));
    }

    /** O passo 1 de um alerta novo de empresa, antes de existir (nada é gravado ao abrir a tela). */
    public function blank(): AlertProfile
    {
        return (new AlertProfile)->forceFill(['status' => 'draft', 'step_reached' => 1]);
    }

    /**
     * Alerta novo para outro produto ou site. Com `$from`, começa com as
     * respostas dele (empresa, stack...), menos nome e domínio.
     */
    public function create(User $user, ?AlertProfile $from = null): AlertProfile
    {
        return DB::transaction(fn () => $this->createLocked($user, $from));
    }

    private function createLocked(User $user, ?AlertProfile $from): AlertProfile
    {
        $stamp = $this->visibility->stampFor($user);

        $profile = AlertProfile::query()->forceCreate($stamp + ['status' => 'draft']);

        if ($from) {
            $rows = $from->answers()->whereNotIn('question_key', self::NOT_COPIED)->get(['question_key', 'value']);

            DB::table('alert_profile_answers')->insert($rows->map(fn ($a) => [
                'profile_id' => $profile->id,
                'question_key' => $a->question_key,
                'value' => json_encode($a->value),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            $this->sync($profile->refresh());
        }

        return $profile;
    }

    /** @return Collection<int, InterviewQuestion> */
    public function questions(?int $step = null): Collection
    {
        return InterviewQuestion::query()
            ->active()
            ->when($step, fn ($q) => $q->where('step', $step))
            ->ordered()
            ->get();
    }

    /** Respostas salvas por cima dos defaults. */
    public function values(AlertProfile $profile): array
    {
        return array_merge(self::DEFAULTS, $profile->answers()->get()->mapWithKeys(
            fn ($a) => [$a->question_key => $a->value]
        )->all());
    }

    /**
     * Opções que a tela mostra: [{value, label, desc, parent, group, recommended}].
     * `parent` é o slug da tecnologia-mãe, usado para filtrar frameworks pela
     * linguagem marcada; `group` é a categoria, para as perguntas com mais de uma.
     */
    public function options(InterviewQuestion $question): array
    {
        $extra = array_map(fn (array $o) => [
            'value' => (string) $o['value'],
            'label' => $o['label'] ?? $o['value'],
            'desc' => $o['desc'] ?? null,
            'parent' => null,
            'parent_name' => null,
            'group' => null,
            'recommended' => (bool) ($o['recommended'] ?? false),
        ], $question->options ?? []);

        if ($question->kind !== 'catalog') {
            return $extra;
        }

        $techs = $this->catalog($question->catalog_category)->map(fn (TechCatalog $t) => [
            'value' => $t->slug,
            'label' => $t->name,
            'desc' => null,
            'parent' => $t->parent?->slug,
            'parent_name' => $t->parent?->name,
            'group' => $t->categoryLabel(),
            'recommended' => false,
        ])->all();

        return array_merge($techs, $extra);
    }

    /** Pergunta de escolha múltipla: sempre aceita uma resposta escrita ("Outro"). */
    public function allowsOther(InterviewQuestion $question): bool
    {
        return in_array($question->kind, ['multi', 'catalog'], true);
    }

    /** Resposta em texto, para a revisão e o admin ("PHP, Python, Protheus (escrito)"). */
    public function display(InterviewQuestion $question, mixed $value, mixed $other = []): string
    {
        if ($question->kind === 'boolean') {
            return $value ? 'sim' : 'não';
        }

        if ($question->kind === 'versions') {
            $versions = array_filter((array) $value, fn ($v) => trim((string) $v) !== '');
            $names = TechCatalog::query()->whereIn('slug', array_keys($versions))->pluck('name', 'slug');

            return implode(', ', array_map(fn ($slug) => ($names[$slug] ?? $slug).' '.$versions[$slug], array_keys($versions))) ?: '—';
        }

        // Escrito que existe no catálogo aparece como o item do catálogo.
        $written = array_map(
            fn ($v) => $this->writtenTech($question, (string) $v)?->name ?? $v.' (escrito)',
            array_filter((array) $other, fn ($v) => trim((string) $v) !== ''),
        );

        if (($value === null || $value === '' || $value === []) && $written === []) {
            return '—';
        }

        if (in_array($question->kind, ['text', 'tags'], true)) {
            return implode(', ', (array) $value);
        }

        $labels = array_column($this->options($question), 'label', 'value');

        return implode(', ', array_merge(array_map(fn ($v) => $labels[$v] ?? $v, (array) $value), $written));
    }

    /** Mapa opção → tecnologias, para a tela filtrar os filhos sem ir ao servidor. */
    public function optionTechMap(?string $questionKey): array
    {
        $question = $questionKey ? InterviewQuestion::query()->where('key', $questionKey)->first() : null;

        if (! $question || $question->kind === 'catalog') {
            return [];
        }

        return collect($question->options ?? [])->mapWithKeys(fn ($o) => [$o['value'] => $o['techs'] ?? []])->all();
    }

    /** Slugs das tecnologias-mãe escolhidas na pergunta de `filter_by`. */
    public function parentFilter(InterviewQuestion $question, array $values): ?array
    {
        if (! $question->filter_by) {
            return null;
        }

        $source = InterviewQuestion::query()->where('key', $question->filter_by)->first();

        return $source ? $this->techSlugsFrom($source, $values[$source->key] ?? null) : [];
    }

    public function visible(InterviewQuestion $question, array $values): bool
    {
        $rule = $question->show_if;

        if (! $rule || empty($rule['key'])) {
            return true;
        }

        $current = $values[$rule['key']] ?? null;
        $current = is_array($current) ? $current : [$current];

        return array_intersect(array_map('strval', array_filter($current, fn ($v) => $v !== null)), $rule['in'] ?? []) !== [];
    }

    /**
     * A tela de um passo: as perguntas com as respostas salvas e, no último,
     * a revisão com a prévia dos últimos 30 dias.
     */
    public function stepPage(AlertProfile $profile, int $step): array
    {
        $values = $this->values($profile);
        $questions = $this->questions($step)->map(fn (InterviewQuestion $q) => [
            'model' => $q,
            'options' => $q->kind === 'versions' ? $this->versionOptions($profile) : $this->options($q),
            'value' => $values[$q->key] ?? null,
            'allows_other' => $this->allowsOther($q),
            'other' => $values[$q->key.self::OTHER] ?? [],
            'visible' => $this->visible($q, $values),
            'filter' => $this->parentFilter($q, $values),
            'filter_map' => $this->optionTechMap($q->filter_by),
        ]);

        $review = null;

        if ($step === self::LAST_STEP) {
            $review = [
                'steps' => $this->questions()
                    ->filter(fn ($q) => $this->visible($q, $values))
                    ->groupBy('step')
                    ->map(fn ($qs) => $qs->map(fn ($q) => [
                        'prompt' => $q->prompt,
                        'answer' => $this->display($q, $values[$q->key] ?? null, $values[$q->key.self::OTHER] ?? []),
                    ])),
                'preview' => $this->preview->run($profile->load(['techs', 'terms', 'domains'])),
                'missing' => $this->missingRequired($profile),
            ];
        }

        $domain = $profile->domains()->with('findings.tech')->first();

        return [
            'profile' => $profile,
            'step' => $step,
            'steps' => InterviewQuestion::STEPS,
            'text' => self::STEP_TEXT[$step],
            'questions' => $questions,
            'domain' => $domain,
            // tecnologia => origem (interview | discovery | admin), para o passo 5 saber o que dá para remover
            'profileTechSources' => $profile->techs()->pluck('alert_profile_techs.source', 'tech_catalog.id')->all(),
            'dnsSuggestions' => $step === 5 ? $this->dnsSuggestionsFor($profile) : [],
            'review' => $review,
        ];
    }

    /**
     * Campos do "Versões do que vocês usam": as tecnologias do perfil em que a
     * versão importa, com a versão que a descoberta viu no site, se viu.
     *
     * @return list<array{value: string, label: string, desc: ?string, parent: null, parent_name: null, group: string, recommended: false}>
     */
    public function versionOptions(AlertProfile $profile): array
    {
        $order = array_flip(array_keys(TechCatalog::CATEGORIES));
        $seen = $profile->domains()->with('findings')->first()?->findings
            ->filter(fn ($f) => $f->tech_id && filled($f->version))
            ->pluck('version', 'tech_id') ?? collect();

        return $profile->techs()->where('tech_catalog.active', true)->whereIn('tech_catalog.category', self::VERSIONED)
            ->whereNotIn('tech_catalog.slug', self::UNVERSIONED)->get()
            ->sortBy(fn (TechCatalog $t) => [$order[$t->category] ?? 99, mb_strtolower($t->name)])
            ->map(fn (TechCatalog $t) => [
                'value' => $t->slug,
                'label' => $t->name,
                'desc' => $seen->has($t->id) ? 'vista no site: '.$seen[$t->id] : null,
                'parent' => null,
                'parent_name' => null,
                'group' => $t->categoryLabel(),
                'recommended' => false,
            ])->values()->all();
    }

    /**
     * Sugestões do DNS ainda sem decisão, com a pergunta onde cada uma entra.
     * Some a que o cliente já marcou por conta própria.
     *
     * @return list<array{slug: string, name: string, evidence: string, target: string}>
     */
    public function dnsSuggestionsFor(AlertProfile $profile): array
    {
        $domain = $profile->domains()->first();

        if (! $domain) {
            return [];
        }

        $values = $this->values($profile);
        $have = $profile->techs()->pluck('slug')->all();
        $techs = TechCatalog::query()->whereIn('slug', array_column($this->dnsSuggester->pending($domain), 'slug'))->get()->keyBy('slug');
        $items = [];

        foreach ($this->dnsSuggester->pending($domain) as $item) {
            $tech = $techs->get($item['slug']);

            if (! $tech || in_array($tech->slug, $have, true) || ! ($target = $this->targetQuestion($tech, $values))) {
                continue;
            }

            $items[] = $item + ['target' => $target->prompt];
        }

        return $items;
    }

    /**
     * Passo do domínio: as sugestões do DNS marcadas entram no perfil como se
     * o cliente as tivesse marcado na entrevista; as outras são dispensadas.
     *
     * @return list<string> nomes do que entrou
     */
    public function decideDnsSuggestions(AlertProfile $profile, MonitoredDomain $domain, array $accepted): array
    {
        $chosen = array_values(array_filter(
            $this->dnsSuggestionsFor($profile),
            fn (array $item) => in_array($item['slug'], $accepted, true),
        ));

        if ($chosen !== []) {
            $this->addTechs($profile, array_column($chosen, 'slug'));
        }

        $this->dnsSuggester->decide($domain, array_column($chosen, 'slug'));

        return array_column($chosen, 'name');
    }

    /**
     * Põe tecnologias do catálogo nas respostas: marcadas na pergunta que as
     * oferece ou, se nenhuma oferece, escritas em "Ferramentas da empresa"
     * (onde viram o item do catálogo). Assim o perfil continua derivado das
     * respostas, e o cliente vê e desmarca na entrevista.
     */
    public function addTechs(AlertProfile $profile, array $slugs): void
    {
        $values = $this->values($profile);
        $changes = [];

        foreach (TechCatalog::query()->whereIn('slug', $slugs)->get() as $tech) {
            $target = $this->targetQuestion($tech, $values);

            if (! $target) {
                continue;
            }

            [$key, $item] = $target->kind === 'catalog' && $this->offers($target, $tech)
                ? [$target->key, $tech->slug]
                : [$target->key.self::OTHER, $tech->name];
            $changes[$key] = array_values(array_unique([...(array) ($changes[$key] ?? $values[$key] ?? []), $item]));
        }

        DB::transaction(function () use ($profile, $changes) {
            foreach ($changes as $key => $value) {
                DB::table('alert_profile_answers')->upsert(
                    [['profile_id' => $profile->id, 'question_key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]],
                    ['profile_id', 'question_key'],
                    ['value', 'updated_at'],
                );
            }
        });

        $this->sync($profile->refresh());
    }

    /**
     * "Recompilar perfil" do admin: refaz o perfil a partir das respostas (com
     * as regras atuais, como escrito que virou item do catálogo) e recompila.
     * Perfil sem respostas (criado à mão) só é recompilado.
     */
    public function rebuild(AlertProfile $profile): void
    {
        $profile->answers()->exists() ? $this->sync($profile) : $this->recompile($profile);
    }

    /** A pergunta visível de catálogo que oferece a tecnologia; sem nenhuma, "Ferramentas da empresa". */
    private function targetQuestion(TechCatalog $tech, array $values): ?InterviewQuestion
    {
        $visible = $this->questions()->filter(fn (InterviewQuestion $q) => $q->kind === 'catalog' && $this->visible($q, $values));

        return $visible->first(fn (InterviewQuestion $q) => $this->offers($q, $tech))
            ?? $visible->firstWhere('key', self::FALLBACK_QUESTION);
    }

    /** A pergunta de catálogo mostra a tecnologia como opção (categoria dela, e não cadastrada pela descoberta). */
    private function offers(InterviewQuestion $question, TechCatalog $tech): bool
    {
        return $question->kind === 'catalog'
            && $tech->origin !== 'discovery'
            && in_array($tech->category, array_map('trim', explode(',', (string) $question->catalog_category)), true);
    }

    /** A tecnologia do catálogo que uma resposta escrita representa (só nas perguntas de tecnologia). */
    private function writtenTech(InterviewQuestion $question, string $label): ?TechCatalog
    {
        return $this->allowsOther($question) && CustomAnswerTerm::kindFor($question->key) === 'tech'
            ? $this->catalogMatcher->find($label)
            : null;
    }

    /**
     * Valida e grava um passo. No passo 5 também cuida do domínio e do aceite.
     *
     * @throws ValidationException
     */
    public function save(AlertProfile $profile, int $step, array $answers, array $extra = []): void
    {
        $questions = $this->questions($step);
        $clean = [];
        $errors = [];

        foreach ($questions as $question) {
            try {
                $clean[$question->key] = $this->normalize($question, $answers[$question->key] ?? null);

                if ($this->allowsOther($question)) {
                    $clean[$question->key.self::OTHER] = $this->normalizeOther($answers[$question->key.self::OTHER] ?? null);
                }
            } catch (\InvalidArgumentException $e) {
                $errors["answers.{$question->key}"] = $e->getMessage();
            }
        }

        $values = array_merge($this->values($profile), $clean);

        // Escreveu o que a própria pergunta oferece ("postgres" em Bancos de dados): vira a opção marcada.
        foreach ($questions as $question) {
            if (isset($clean[$question->key.self::OTHER])) {
                [$clean[$question->key], $clean[$question->key.self::OTHER]] = $this->absorbWritten($question, $clean[$question->key], $clean[$question->key.self::OTHER], $values);
            }
        }

        $values = array_merge($values, $clean);

        foreach ($questions as $question) {
            if ($question->required && $this->visible($question, $values) && $this->unanswered($question, $clean)) {
                $errors["answers.{$question->key}"] ??= 'Esta resposta é obrigatória.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($profile, $step, $clean, $values, $extra) {
            foreach ($clean as $key => $value) {
                DB::table('alert_profile_answers')->upsert(
                    [['profile_id' => $profile->id, 'question_key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]],
                    ['profile_id', 'question_key'],
                    ['value', 'updated_at'],
                );
            }

            if ($step === 5) {
                $this->saveDomain($profile, $values, (bool) ($extra['consent'] ?? false), $extra['ip'] ?? null);
            }

            $profile->forceFill(['step_reached' => max($profile->step_reached, min($step + 1, self::LAST_STEP))])->save();
        });

        $this->sync($profile->refresh());

        // Domínio informado: o DNS público dele vira sugestões (só leitura de DNS, nada no site).
        if ($step === 5 && ($domain = $profile->domains()->first())) {
            $this->dnsSuggester->refresh($domain, $profile->techs()->pluck('slug')->all());
        }
    }

    /**
     * Respostas escritas que a pergunta já oferece como opção passam para as
     * marcadas. Num framework, só se a linguagem dele estiver marcada: senão a
     * opção ficaria escondida, e o escrito some da tela.
     *
     * @return array{0: mixed, 1: list<string>} [marcadas, escritas]
     */
    private function absorbWritten(InterviewQuestion $question, mixed $chosen, array $written, array $values): array
    {
        if ($question->kind !== 'catalog' || CustomAnswerTerm::kindFor($question->key) !== 'tech') {
            return [$chosen, $written];
        }

        $chosen = (array) $chosen;
        $parents = $this->parentFilter($question, $values);
        $kept = [];

        foreach ($written as $label) {
            $tech = $this->catalogMatcher->find($label);
            $shown = $parents === null || in_array($tech?->parent?->slug, $parents, true);

            if ($tech && $this->offers($question, $tech) && $shown) {
                $chosen[] = $tech->slug;
            } else {
                $kept[] = $label;
            }
        }

        return [array_values(array_unique($chosen)), $kept];
    }

    /** Primeira pergunta obrigatória sem resposta, para a revisão mandar o cliente de volta. */
    public function missingRequired(AlertProfile $profile): ?InterviewQuestion
    {
        $values = $this->values($profile);

        return $this->questions()->first(
            fn (InterviewQuestion $q) => $q->required && $this->visible($q, $values) && $this->unanswered($q, $values)
        );
    }

    public function finish(AlertProfile $profile): void
    {
        $profile->forceFill([
            'status' => $profile->isPaused() ? 'paused' : 'active',
            'completed_at' => $profile->completed_at ?? now(),
            'step_reached' => self::LAST_STEP,
        ])->save();

        $this->sync($profile);
    }

    /** "Salvar perfil" na revisão: conclui a entrevista. Devolve se foi a primeira ativação. */
    public function activate(AlertProfile $profile): bool
    {
        $first = $profile->completed_at === null;
        $this->finish($profile);

        // Primeira ativação: a última semana entra como histórico, sem notificar.
        if ($first) {
            $this->rematcher->run(now()->subDays(7), $profile->id);
        }

        return $first;
    }

    /** Recalcula colunas, tecnologias, termos e queries a partir das respostas. */
    public function sync(AlertProfile $profile): void
    {
        $values = $this->values($profile);
        $questions = $this->questions();
        $visible = fn (string $key) => ($q = $questions->firstWhere('key', $key)) ? $this->visible($q, $values) : false;
        $get = fn (string $key, $default = null) => $visible($key) ? ($values[$key] ?? $default) : $default;

        $brands = array_values(array_unique(array_filter((array) $get('brands', []))));

        // O que o cliente escreveu em "Outro", por pergunta visível. O que existe
        // no catálogo entra como o item do catálogo, sem tradução da IA.
        $custom = [];
        $linked = [];

        foreach ($questions as $question) {
            if (! $this->allowsOther($question) || ! $this->visible($question, $values)) {
                continue;
            }

            foreach ((array) ($values[$question->key.self::OTHER] ?? []) as $label) {
                if ($tech = $this->writtenTech($question, (string) $label)) {
                    $linked[] = $tech->slug;
                } else {
                    $custom[$question->key][] = $label;
                }
            }
        }

        $versions = array_filter((array) $get('versions', []), fn ($v) => trim((string) $v) !== '');

        DB::transaction(function () use ($profile, $questions, $values, $get, $brands, $custom, $linked, $versions) {
            $profile->forceFill([
                'name' => $get('alert_name'),
                'company_name' => $get('company_name'),
                'brands' => $brands,
                'sectors' => array_values((array) $get('sectors', [])),
                'regions' => array_values((array) $get('regions', [])),
                'server_os' => $get('server_os'),
                'min_criticity' => in_array($values['min_criticity'] ?? null, AlertProfile::LEVELS, true) ? $values['min_criticity'] : 'high',
                'attack_types' => array_values((array) $get('attack_types', [])),
                'include_brand_mentions' => (bool) ($values['brand_mentions'] ?? true),
                'include_sector_news' => (bool) ($values['sector_news'] ?? false),
                'notify_case_updates' => (bool) ($values['case_updates'] ?? true),
                'language' => in_array($values['language'] ?? null, ['pt', 'en'], true) ? $values['language'] : 'pt',
                'custom_answers' => $custom === [] ? null : $custom,
                'tech_versions' => $versions === [] ? null : $versions,
            ]);

            $this->registerCustom($custom);

            // Tecnologias da entrevista são refeitas; as da descoberta e do admin ficam.
            $slugs = $linked;
            $terms = [];

            foreach ($questions as $question) {
                if (! $this->visible($question, $values)) {
                    continue;
                }

                $slugs = array_merge($slugs, $this->techSlugsFrom($question, $values[$question->key] ?? null));

                if ($question->kind === 'tags' && $question->key !== 'brands') {
                    $terms = array_merge($terms, (array) ($values[$question->key] ?? []));
                }
            }

            $ids = TechCatalog::query()->whereIn('slug', array_unique($slugs))->pluck('id')->all();
            $kept = DB::table('alert_profile_techs')->where('profile_id', $profile->id)->where('source', '!=', 'interview')->pluck('tech_id')->all();

            DB::table('alert_profile_techs')->where('profile_id', $profile->id)->where('source', 'interview')->delete();
            DB::table('alert_profile_techs')->insert(array_map(fn ($id) => [
                'profile_id' => $profile->id, 'tech_id' => $id, 'source' => 'interview', 'created_at' => now(),
            ], array_values(array_diff($ids, $kept))));

            $profile->terms()->where('source', 'interview')->delete();

            foreach (array_unique(array_map('trim', $terms)) as $term) {
                if ($term !== '') {
                    $profile->terms()->firstOrCreate(['value' => mb_substr($term, 0, 120)], ['source' => 'interview']);
                }
            }

            $profile->save();
        });

        $this->recompile($profile->refresh());
    }

    /** Regrava as queries e, se o perfil está ativo, o percolator. */
    public function recompile(AlertProfile $profile): void
    {
        $profile->unsetRelation('techs')->unsetRelation('terms')->unsetRelation('domains');

        $match = $this->compiler->matchQuery($profile);
        $compiled = $this->compiler->compiledQuery($profile);

        if ($match !== $profile->match_query || $compiled !== $profile->compiled_query) {
            $profile->forceFill([
                'match_query' => $match,
                'compiled_query' => $compiled,
                'query_version' => $profile->query_version + 1,
            ])->save();
        }

        $this->index->put($profile);
    }

    /**
     * Slugs do catálogo que uma resposta representa: a própria resposta numa
     * pergunta de catálogo, ou `techs` de uma opção (servidor Linux → linux).
     */
    public function techSlugsFrom(InterviewQuestion $question, mixed $value): array
    {
        $chosen = array_map('strval', array_filter((array) $value, fn ($v) => $v !== null && $v !== ''));
        $slugs = [];

        foreach ($chosen as $item) {
            $option = $question->option($item);

            if ($option) {
                $slugs = array_merge($slugs, (array) ($option['techs'] ?? []));
            } elseif ($question->kind === 'catalog') {
                $slugs[] = $item;
            }
        }

        return array_values(array_unique($slugs));
    }

    private function saveDomain(AlertProfile $profile, array $values, bool $consent, ?string $ip): void
    {
        $wanted = ($values['has_domain'] ?? null) === 'sim' ? ($values['domain'] ?? null) : null;
        $current = $profile->domains()->first();

        if (! $wanted) {
            $this->forgetDomain($profile);

            return;
        }

        // O dono da posse: a conta (instalação de um dono só).
        $owner = DomainOwner::of($profile->user_id);

        if (! $current || $current->domain !== $wanted) {
            $this->forgetDomain($profile);
            $current = $profile->domains()->create(['domain' => $wanted]);
        }

        // O domínio já nasce comprovado (instalação de um dono só): quem decide se a descoberta
        // entra na fila agora é o aceite, não mais a posse. `$wasDiscoverable` é de antes do aceite
        // desta chamada, para só enfileirar na transição de "sem aceite" para "com aceite".
        $wasDiscoverable = $current->canDiscover();

        $version = (string) Settings::get('alerts.consent_version');

        if ($consent && ! $current->consent_at) {
            $current->forceFill([
                'consent_at' => now(),
                'consent_ip' => $ip,
                'consent_version' => $version,
                'discovery_status' => 'pending',
            ])->save();
        } elseif ($consent && $current->consent_version !== $version) {
            // Aceite renovado no termo atual (o aviso aparece junto do checkbox). Vale
            // a partir da próxima varredura mensal: renovar não faz o site ser lido de novo.
            $current->forceFill(['consent_at' => now(), 'consent_ip' => $ip, 'consent_version' => $version])->save();
        } elseif (! $consent && $current->consent_at) {
            // Revogar apaga o que foi descoberto, inclusive o que entrou no perfil.
            $current->findings()->delete();
            $this->forgetDiscovered($profile);
            $current->forceFill([
                'consent_at' => null,
                'consent_ip' => null,
                'consent_version' => null,
                'discovery_status' => 'pending',
                'next_run_at' => null,
                'last_log' => [],
                'hints' => [],
            ])->save();
        }

        // A posse é do dono: todo domínio nasce comprovado (instalação de um dono só), e com o
        // aceite a descoberta já entra na fila.
        $this->ownership->claim($owner, $wanted, 'news');
        $this->ownership->apply($current);

        if (! $wasDiscoverable && $current->refresh()->canDiscover() && Settings::bool('alerts.enabled') && Settings::bool('alerts.discovery_enabled')) {
            // Depois do commit: a entrevista grava o domínio numa transação.
            DiscoverDomainStack::dispatch($current->id)->afterCommit();
        }
    }

    /** Domínio trocado ou removido: some com ele o que a descoberta pôs no perfil. */
    private function forgetDomain(AlertProfile $profile): void
    {
        $profile->domains()->get()->each->delete();
        $this->forgetDiscovered($profile);
    }

    private function forgetDiscovered(AlertProfile $profile): void
    {
        DB::table('alert_profile_techs')->where('profile_id', $profile->id)->where('source', 'discovery')->delete();
    }

    private function normalize(InterviewQuestion $question, mixed $value): mixed
    {
        switch ($question->kind) {
            case 'text':
                $text = trim((string) (is_array($value) ? '' : $value));

                if (mb_strlen($text) > 255) {
                    throw new \InvalidArgumentException('Use no máximo 255 caracteres.');
                }

                if ($question->key === 'domain' && $text !== '') {
                    return DomainName::normalize($text)
                        ?? throw new \InvalidArgumentException('Informe um domínio válido, como empresa.com.br (sem IP, porta ou domínio interno).');
                }

                return $text === '' ? null : $text;

            case 'tags':
                $items = is_array($value) ? $value : explode(',', (string) $value);
                $seen = [];

                foreach ($items as $item) {
                    $item = trim(preg_replace('/\s+/', ' ', (string) $item));

                    if ($item !== '' && ! isset($seen[mb_strtolower($item)])) {
                        if (mb_strlen($item) > 120) {
                            throw new \InvalidArgumentException('Cada termo pode ter no máximo 120 caracteres.');
                        }

                        $seen[mb_strtolower($item)] = $item;
                    }
                }

                if (count($seen) > 30) {
                    throw new \InvalidArgumentException('Use no máximo 30 termos.');
                }

                return array_values($seen);

            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOL);

            case 'versions':
                // {slug: "8.2"}. Vai para o prompt da IA: começa por número e só tem o que uma versão tem.
                $versions = [];

                foreach (is_array($value) ? $value : [] as $slug => $version) {
                    $version = trim((string) preg_replace('/\s+/', ' ', is_array($version) ? '' : (string) $version));

                    if ($version === '') {
                        continue;
                    }

                    if (! preg_match('/^[vV]?\d[0-9A-Za-z .+\-_*\/()]{0,28}$/', $version)) {
                        throw new \InvalidArgumentException('Escreva só a versão, começando pelo número: 8.2, 22.04 LTS, 1.29.');
                    }

                    $versions[(string) $slug] = $version;
                }

                if (count($versions) > 60) {
                    throw new \InvalidArgumentException('Informe no máximo 60 versões.');
                }

                $known = TechCatalog::query()->whereIn('slug', array_keys($versions))->pluck('slug')->all();

                return array_intersect_key($versions, array_flip($known));

            case 'single':
                $value = is_array($value) ? null : (string) $value;

                if ($value === null || $value === '') {
                    return null;
                }

                if (! in_array($value, array_column($this->options($question), 'value'), true)) {
                    throw new \InvalidArgumentException('Opção inválida.');
                }

                return $value;

            default: // multi, catalog
                $chosen = array_values(array_unique(array_map('strval', array_filter((array) $value, fn ($v) => $v !== null && $v !== ''))));

                if (array_diff($chosen, array_column($this->options($question), 'value')) !== []) {
                    throw new \InvalidArgumentException('Opção inválida.');
                }

                return $chosen;
        }
    }

    /**
     * Cada resposta escrita nova ganha uma linha de termos e vai para a IA
     * traduzir; a que já existe (de outro cliente) é reaproveitada.
     */
    private function registerCustom(array $custom): void
    {
        foreach ($custom as $questionKey => $labels) {
            foreach ($labels as $label) {
                $row = CustomAnswerTerm::query()->firstOrCreate(
                    ['kind' => CustomAnswerTerm::kindFor($questionKey), 'normalized' => CustomAnswerTerm::normalize($label)],
                    ['label' => $label, 'status' => 'pending'],
                );

                if ($row->wasRecentlyCreated) {
                    ExpandCustomAnswer::dispatch($row->id)->afterCommit();
                }
            }
        }
    }

    /** "Outro": a mesma limpeza de uma lista de termos, com limites menores. */
    private function normalizeOther(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $seen = [];

        foreach ($items as $item) {
            $item = trim((string) preg_replace('/\s+/', ' ', (string) $item));

            if ($item === '' || isset($seen[CustomAnswerTerm::normalize($item)])) {
                continue;
            }

            if (mb_strlen($item) > 80) {
                throw new \InvalidArgumentException('Cada resposta escrita pode ter no máximo 80 caracteres.');
            }

            $seen[CustomAnswerTerm::normalize($item)] = $item;
        }

        if (count($seen) > self::MAX_OTHER) {
            throw new \InvalidArgumentException('Escreva no máximo '.self::MAX_OTHER.' opções.');
        }

        return array_values($seen);
    }

    /** Sem opção marcada e sem nada escrito em "Outro". */
    private function unanswered(InterviewQuestion $question, array $values): bool
    {
        return $this->isEmpty($values[$question->key] ?? null)
            && (! $this->allowsOther($question) || $this->isEmpty($values[$question->key.self::OTHER] ?? null));
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @return Collection<int, TechCatalog> */
    private function catalog(?string $categories): Collection
    {
        $key = (string) $categories;

        // Só o catálogo curado: o que a descoberta cadastrou sozinha veio do
        // domínio de um cliente e não vira opção na entrevista dos outros.
        return $this->catalogCache[$key] ??= TechCatalog::query()
            ->active()
            ->where('origin', '!=', 'discovery')
            ->with('parent:id,slug,name')
            ->whereIn('category', array_filter(array_map('trim', explode(',', $key))))
            ->orderByRaw('array_position(?::text[], category)', ['{'.$key.'}'])
            ->orderBy('position')
            ->get();
    }
}
