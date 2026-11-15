<?php

namespace App\Http\Requests\Alerts\Domain;

class DecideDnsSuggestionsRequest extends DomainRequest
{
    /** Sem domínio no alerta não há sugestão do DNS: 404. */
    public function authorize(): bool
    {
        return $this->domain() !== null;
    }

    public function rules(): array
    {
        return [
            'adicionar' => ['nullable', 'array', 'max:50'],
            'adicionar.*' => ['string', 'max:80'],
        ];
    }

    /** Slugs marcados para entrar no perfil; "Agora não" dispensa todas. */
    public function accepted(): array
    {
        return $this->input('acao') === 'dispensar' ? [] : array_values(array_map('strval', (array) $this->input('adicionar', [])));
    }
}
