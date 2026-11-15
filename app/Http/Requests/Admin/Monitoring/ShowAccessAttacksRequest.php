<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;
use App\Services\Admin\AccessReport;
use App\Support\AttackSignatures;

/** Admin → Acessos, aba Ataques: o período, a categoria, o IP, o país, os nossos e só os que varreram. */
class ShowAccessAttacksRequest extends Request
{
    public function rules(): array
    {
        return [
            'periodo' => ['nullable', 'in:'.implode(',', array_keys(AccessReport::PERIODS))],
            // "recusado": o evento sem categoria (só a resposta 4xx/5xx).
            'categoria' => ['nullable', 'in:recusado,'.implode(',', array_keys(AttackSignatures::CATEGORIES))],
            'varredura' => ['nullable', 'in:1'],
            // "Ver mais" de IPs com mais eventos: fora dos filtros.
            'mais' => ['nullable', 'in:ips'],
            'ip' => ['nullable', 'ip'],
            'pais' => ['nullable', 'string', 'size:2'],
            'nossos' => ['nullable', 'in:esconder,mostrar,so'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['ip.ip' => 'IP inválido.'];
    }
}
