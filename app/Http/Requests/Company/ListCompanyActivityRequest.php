<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\CompanyAudit;
use Illuminate\Validation\Rule;

/** Os filtros da Atividade: período (de, até), pessoa e tipo. Filtro inválido some, em vez de dar erro. */
class ListCompanyActivityRequest extends Request
{
    public function rules(): array
    {
        return [
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'pessoa' => ['nullable', 'regex:/^(\d{1,18}|secmon)$/'],
            'tipo' => ['nullable', Rule::in(array_keys(CompanyAudit::ACTIONS))],
        ];
    }

    /** @return array{from: ?string, to: ?string, actor: int|string|null, action: ?string} */
    public function filters(): array
    {
        $valid = $this->validator->valid();

        return [
            'from' => $valid['de'] ?? null,
            'to' => $valid['ate'] ?? null,
            'actor' => match (true) {
                ! isset($valid['pessoa']) => null,
                $valid['pessoa'] === 'secmon' => 'secmon',
                default => (int) $valid['pessoa'],
            },
            'action' => $valid['tipo'] ?? null,
        ];
    }

    /** Filtro errado na URL não vira página de erro: fica de fora. */
    protected function failedValidation($validator): void {}
}
