<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\Sectors;

/** O setor da URL é da empresa de quem pede; senão, 404. */
class RenameCompanySectorRequest extends Request
{
    public function authorize(): bool
    {
        return $this->route('sector')->company_id === $this->user()->companyMembership?->company_id;
    }

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
