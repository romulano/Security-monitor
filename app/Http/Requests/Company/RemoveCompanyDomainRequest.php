<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/**
 * Remover um domínio da empresa: o domínio e a contagem que o diálogo mostrou ("id-alertas-radares-webhooks"), no corpo,
 * como apagar setor: a reautenticação vem antes e não diz se o id existe. O serviço confere se a conta configura o
 * domínio e se a contagem ainda é a mesma.
 */
class RemoveCompanyDomainRequest extends Request
{
    public function rules(): array
    {
        return ['dominio' => ['required', 'string', 'regex:/^[1-9]\d{0,17}(-\d{1,9}){3}$/']];
    }

    public function messages(): array
    {
        return ['dominio.*' => 'Escolha o domínio que vai remover.'];
    }

    public function domainId(): int
    {
        return (int) explode('-', $this->validated('dominio'))[0];
    }

    /** @return array{alertas: int, radares: int, webhooks: int} */
    public function expected(): array
    {
        [, $alerts, $watches, $webhooks] = array_map('intval', explode('-', $this->validated('dominio')));

        return ['alertas' => $alerts, 'radares' => $watches, 'webhooks' => $webhooks];
    }
}
