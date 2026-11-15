<?php

namespace App\Http\Requests\Admin\Alerts\Catalog;

use App\Http\Requests\Request;
use App\Models\TechCatalog;
use App\Services\Admin\CatalogText;
use Illuminate\Validation\Rule;

/** Formulário de tecnologia do catálogo (criar e editar): o slug só se escolhe ao criar. */
abstract class TechRequest extends Request
{
    private array $fields;

    /** A tecnologia em edição; null ao criar. */
    abstract protected function editing(): ?TechCatalog;

    public function rules(): array
    {
        $tech = $this->editing();

        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => [$tech ? 'prohibited' : 'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('tech_catalog', 'slug')],
            'category' => ['required', Rule::in(array_keys(TechCatalog::CATEGORIES))],
            'parent_id' => ['nullable', 'integer', 'exists:tech_catalog,id', Rule::notIn([$tech?->id])],
            'terms' => ['required', 'string', 'max:2000'],
            'detect' => ['nullable', 'string', 'max:4000'],
            'strict' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use letras minúsculas, números e hífen.',
            'terms.required' => 'Informe ao menos um termo de busca.',
        ];
    }

    /** Converte os textos do formulário; linha de detecção malformada volta como erro em `detect`. */
    protected function passedValidation(): void
    {
        $data = $this->validated();

        $this->fields = array_filter([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
            'category' => $data['category'],
            'parent_id' => $data['parent_id'] ?? null,
            'terms' => CatalogText::parseTerms($data['terms']),
            'detect' => CatalogText::parseDetect((string) ($data['detect'] ?? '')),
            'strict' => $this->boolean('strict'),
            'active' => $this->boolean('active'),
        ], fn ($v, $k) => $v !== null || $k === 'parent_id', ARRAY_FILTER_USE_BOTH);
    }

    /** Os campos para gravar no catálogo, com termos e regras de detecção já em lista. */
    public function fields(): array
    {
        return $this->fields;
    }
}
