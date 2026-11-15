<?php

namespace App\Http\Requests\Site;

use App\Http\Requests\Request;
use App\Services\Monitoring\PageViewRecorder;

/**
 * O tempo que a página ficou aberta, mandado pelo navegador ao sair (resources/js/app.js),
 * e, no primeiro envio de quem navega sem login, o código do aparelho (DeviceSightings):
 * só o hash SHA-256 em hex, nenhum sinal cru.
 */
class StorePageViewDurationRequest extends Request
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'uuid'],
            'ms' => ['required', 'integer', 'min:0', 'max:'.PageViewRecorder::MAX_DURATION_MS * 2],
            'aparelho' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /** O código do aparelho, se veio; nulo também quando o navegador manda o sinal Global Privacy Control. */
    public function device(): ?string
    {
        return $this->header('Sec-GPC') === '1' ? null : $this->validated('aparelho');
    }
}
