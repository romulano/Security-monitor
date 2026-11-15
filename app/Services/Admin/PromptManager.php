<?php

namespace App\Services\Admin;

use App\Models\PromptRevision;
use App\Models\User;
use App\Services\Prompts\PromptCatalog;
use App\Services\Prompts\PromptPreview;
use App\Support\Prompts;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Aba Prompts do admin: o texto que o hermes recebe em cada etapa. Cada
 * edição vira uma revisão (dá para voltar a qualquer uma) e vale na próxima
 * chamada da IA.
 */
class PromptManager
{
    private const HISTORY = 20;

    public function __construct(private PromptPreview $preview) {}

    /**
     * Os grupos com as etapas e o estado de cada trecho; com $group, só ele.
     *
     * @return list<array{key: string, label: string, stages: list<array<string, mixed>>}>
     */
    public function overview(?string $group = null): array
    {
        $current = $this->current();

        return collect(PromptCatalog::GROUPS)
            ->filter(fn (string $label, string $key) => $group === null || $key === $group)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'stages' => collect(PromptCatalog::STAGES)
                    ->filter(fn (array $stage) => $stage['group'] === $key)
                    ->map(fn (array $stage, string $slug) => [
                        ...$stage,
                        'slug' => $slug,
                        'on' => Prompts::stageOn($slug),
                        'parts' => array_map(fn (string $part) => $this->state($part, $current->get($part)), $stage['parts']),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** Liga ou desliga a etapa. @return string o aviso para o admin */
    public function toggle(string $slug): string
    {
        $on = ! Prompts::stageOn($slug);
        Settings::set(Prompts::stageKey($slug), $on);

        $label = PromptCatalog::STAGES[$slug]['label'];

        return $on
            ? "{$label}: ligada. A próxima vez que a etapa rodar, a IA é chamada."
            : "{$label}: desligada. ".PromptCatalog::STAGES[$slug]['off'];
    }

    /** @return array<string, mixed> a etapa com o texto, o padrão, a parte fixa e o histórico de cada trecho, e a prévia */
    public function stage(string $slug): array
    {
        $stage = PromptCatalog::STAGES[$slug];
        $current = $this->current();
        $history = PromptRevision::query()
            ->whereIn('key', $stage['parts'])
            ->with('user:id,name')
            ->latest('id')
            ->get()
            ->groupBy('key');

        return [
            ...$stage,
            'slug' => $slug,
            'group_label' => PromptCatalog::GROUPS[$stage['group']],
            'on' => Prompts::stageOn($slug),
            'parts' => array_map(fn (string $key) => [
                ...$this->state($key, $current->get($key)),
                'text' => Prompts::text($key),
                'default' => Prompts::default($key),
                'fixed' => PromptCatalog::fixed($key),
                'history' => $history->get($key, collect())->take(self::HISTORY)->values()->all(),
            ], $stage['parts']),
            'preview' => $this->preview->stage($slug),
        ];
    }

    /** @return string o aviso para o admin */
    public function save(string $key, string $body, ?User $user): string
    {
        $body = rtrim(str_replace(["\r\n", "\r"], "\n", $body));

        if ($body === Prompts::text($key)) {
            return 'Nada mudou no texto.';
        }

        // Igual ao padrão: volta a seguir o padrão (e as mudanças futuras dele).
        $this->record($key, $body === Prompts::default($key) ? null : $body, $user);

        return 'Prompt salvo. A próxima chamada do hermes já usa este texto.';
    }

    public function reset(string $key, ?User $user): string
    {
        if (! $this->current()->get($key)?->body) {
            return 'Este trecho já usa o texto padrão.';
        }

        $this->record($key, null, $user);

        return 'Trecho de volta ao texto padrão.';
    }

    public function restore(PromptRevision $revision, ?User $user): string
    {
        if ($revision->body !== null) {
            return $this->save($revision->key, $revision->body, $user);
        }

        return $this->reset($revision->key, $user);
    }

    /** Onde o admin volta depois de mexer num trecho. */
    public function stageOf(string $key): string
    {
        return PromptCatalog::stageOf($key);
    }

    private function record(string $key, ?string $body, ?User $user): void
    {
        PromptRevision::query()->create([
            'key' => $key,
            'body' => $body,
            'base_hash' => Prompts::defaultHash($key),
            'user_id' => $user?->id,
        ]);

        Prompts::forget();
    }

    /** @return Collection<string, PromptRevision> a revisão em uso de cada trecho que já foi mexido */
    private function current(): Collection
    {
        return PromptRevision::query()->current()->with('user:id,name')->get()->keyBy('key');
    }

    /** @return array<string, mixed> */
    private function state(string $key, ?PromptRevision $revision): array
    {
        $edited = $revision?->body !== null;

        return [
            ...PromptCatalog::PARTS[$key],
            'key' => $key,
            'edited' => $edited,
            'changed_at' => $revision?->created_at,
            'changed_by' => $revision?->user?->name,
            // O código mudou o padrão depois da edição: a edição pode ter ficado para trás.
            'default_changed' => $edited && $revision->base_hash !== Prompts::defaultHash($key),
        ];
    }
}
