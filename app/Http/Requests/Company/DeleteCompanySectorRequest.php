<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Request;

/**
 * Apagar um setor: a opção escolhida leva o setor e a contagem que o diálogo mostrou ("id-alertas-radares-github-contas").
 * O serviço confere se o setor é da empresa e se a contagem ainda é a mesma (senão, recusa para confirmar de novo).
 */
class DeleteCompanySectorRequest extends Request
{
    public function rules(): array
    {
        return ['setor' => ['required', 'string', 'regex:/^[1-9]\d{0,17}(-\d{1,9}){4,6}$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha o setor que vai apagar.'];
    }

    public function sectorId(): int
    {
        return (int) explode('-', $this->validated('setor'))[0];
    }

    /** @return array{alertas: int, radares: int, github: int, contas: int, dominios?: int, canais?: int} o 6º número, os domínios, e o 7º, os canais, só quando há algum */
    public function expected(): array
    {
        $parts = array_map('intval', explode('-', $this->validated('setor')));
        [, $alerts, $watches, $monitors, $accounts] = $parts;

        return ['alertas' => $alerts, 'radares' => $watches, 'github' => $monitors, 'contas' => $accounts]
            + (isset($parts[5]) ? ['dominios' => $parts[5]] : []) + (isset($parts[6]) ? ['canais' => $parts[6]] : []);
    }
}
