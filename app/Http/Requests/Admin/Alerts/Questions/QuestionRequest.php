<?php

namespace App\Http\Requests\Admin\Alerts\Questions;

use App\Http\Requests\Request;
use App\Models\InterviewQuestion;
use App\Models\TechCatalog;
use App\Services\Admin\CatalogText;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formulário de pergunta da entrevista (criar e editar): a chave só se escolhe
 * ao criar, e pergunta de sistema não muda de tipo nem de opções.
 */
abstract class QuestionRequest extends Request
{
    private array $fields;

    /** A pergunta em edição; null ao criar. */
    abstract protected function editing(): ?InterviewQuestion;

    public function rules(): array
    {
        $question = $this->editing();
        $system = $question?->is_system ?? false;

        return [
            'prompt' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:500'],
            'step' => ['required', 'integer', 'between:1,6'],
            'required' => ['boolean'],
            'active' => ['boolean'],
            'key' => [$question ? 'prohibited' : 'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('interview_questions', 'key')],
            'kind' => [$system ? 'prohibited' : 'required', Rule::in(['text', 'tags', 'single', 'multi', 'catalog'])],
            'catalog_category' => ['nullable', 'required_if:kind,catalog', Rule::in(array_keys(TechCatalog::CATEGORIES))],
            'options' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return ['key.regex' => 'Use letras minúsculas, números e _.'];
    }

    /** Converte as opções do formulário; escolha única ou múltipla sem opção volta como erro em `options`. */
    protected function passedValidation(): void
    {
        $data = $this->validated();

        $this->fields = [
            'prompt' => $data['prompt'],
            'help' => $data['help'] ?? null,
            'step' => (int) $data['step'],
            'required' => $this->boolean('required'),
            'active' => $this->boolean('active'),
        ];

        // Perguntas de sistema: só texto, passo, obrigatoriedade e se aparece.
        if ($this->editing()?->is_system ?? false) {
            return;
        }

        $options = CatalogText::parseOptions((string) ($data['options'] ?? ''));

        if (in_array($data['kind'], ['single', 'multi'], true) && $options === []) {
            throw ValidationException::withMessages(['options' => 'Escolha única e múltipla escolha precisam de opções (uma por linha: valor|rótulo|descrição).']);
        }

        $this->fields += array_filter([
            'key' => $data['key'] ?? null,
            'kind' => $data['kind'],
            'catalog_category' => $data['kind'] === 'catalog' ? $data['catalog_category'] : null,
            'options' => $options,
        ], fn ($v) => $v !== null);
    }

    /** Os campos para gravar na pergunta, com as opções já em lista. */
    public function fields(): array
    {
        return $this->fields;
    }
}
