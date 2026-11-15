<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;
use App\Services\Company\Visibility;

/** O domínio da URL é um que a conta vê (senão, 404); quem cadastra é conferido no CompanySubdomains. */
class StoreCompanySubdomainRequest extends Request
{
    public function authorize(): bool
    {
        return app(Visibility::class)->sees($this->user(), $this->route('domain'));
    }

    public function rules(): array
    {
        return ['nome' => ['required', 'string', 'max:300']];
    }

    public function messages(): array
    {
        return ['nome.*' => 'Escreva o subdomínio, como login.empresa.com.br.'];
    }

    public function name(): string
    {
        return $this->validated('nome');
    }
}
