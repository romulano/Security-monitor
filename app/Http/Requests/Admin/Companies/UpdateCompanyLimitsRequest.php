<?php

namespace App\Http\Requests\Admin\Companies;

use App\Http\Requests\Request;
use App\Models\Company;

/** Cada limite: um número de 0 em diante, ou vazio para voltar ao padrão da config. */
class UpdateCompanyLimitsRequest extends Request
{
    public function rules(): array
    {
        return collect(Company::LIMITS)->mapWithKeys(fn ($name) => ["limits.{$name}" => ['nullable', 'integer', 'min:0', 'max:100000']])
            ->put('limits', ['present', 'array'])
            ->all();
    }

    public function messages(): array
    {
        return [
            'limits.*.integer' => 'Cada limite é um número inteiro (vazio = o padrão).',
            'limits.*.min' => 'Um limite não pode ser negativo.',
            'limits.*.max' => 'Um limite pode ir até 100000.',
        ];
    }

    /** @return array<string, int|null> */
    public function limits(): array
    {
        $limits = (array) $this->validated('limits', []);

        return collect(Company::LIMITS)->mapWithKeys(fn ($name) => [$name => isset($limits[$name]) ? (int) $limits[$name] : null])->all();
    }
}
