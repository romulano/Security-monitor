<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;
use App\Services\Admin\IpTimeline;

/** O × de um acesso na ficha do IP: o período da ficha vem no endereço, para voltar a ela. */
class DestroyAccessRequest extends Request
{
    public function rules(): array
    {
        return [
            'periodo' => ['nullable', 'in:'.implode(',', array_keys(IpTimeline::PERIODS))],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
