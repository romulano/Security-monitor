<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\Sectors;

/** O nome do setor novo; o nome repetido (sem diferença de caixa) e o teto são do serviço. */
class StoreCompanySectorRequest extends Request
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:'.Sectors::NAME_MAX]];
    }

    public function messages(): array
    {
        return ['name.*' => 'O nome do setor precisa ter de 1 a '.Sectors::NAME_MAX.' caracteres.'];
    }

    public function name(): string
    {
        return $this->validated('name');
    }
}
