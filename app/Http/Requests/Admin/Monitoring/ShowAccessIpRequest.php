<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;
use App\Services\Admin\IpTimeline;

/** Admin → Acessos, ficha de um IP: o IP (obrigatório e válido) e o período, até os 180 dias guardados. */
class ShowAccessIpRequest extends Request
{
    public function rules(): array
    {
        return [
            'ip' => ['required', 'ip'],
            'periodo' => ['nullable', 'in:'.implode(',', array_keys(IpTimeline::PERIODS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['ip.required' => 'Informe o IP.', 'ip.ip' => 'IP inválido.'];
    }
}
