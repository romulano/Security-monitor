<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Services\Lookalike\LookalikeVerdicts;

class StoreLookalikeVerdictRequest extends OwnedLookalikeRequest
{
    public function rules(): array
    {
        return [
            'acao' => ['required', 'in:'.implode(',', array_keys(LookalikeVerdicts::VERDICTS))],
            'note' => ['nullable', 'string'],
        ];
    }

    /** `nosso`, `seguro`, `golpe` ou `desfazer`. */
    public function action(): string
    {
        return $this->validated('acao');
    }

    /** O motivo; false quando o formulário não tem o campo (botão rápido da lista), e aí o motivo que existe fica. */
    public function note(): string|false|null
    {
        return $this->has('note') ? $this->validated('note') : false;
    }
}
