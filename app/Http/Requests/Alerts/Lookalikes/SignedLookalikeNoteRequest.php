<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;

/** O motivo escrito na página que os botões do aviso abrem: assinada, sem login. */
class SignedLookalikeNoteRequest extends Request
{
    public function rules(): array
    {
        return [
            'acao' => ['required', 'in:nosso,seguro,golpe'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function action(): string
    {
        return $this->validated('acao');
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
