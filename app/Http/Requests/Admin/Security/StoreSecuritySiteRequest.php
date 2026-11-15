<?php

namespace App\Http\Requests\Admin\Security;

use App\Http\Requests\Request;

class StoreSecuritySiteRequest extends Request
{
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253'],
            'consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'host.required' => 'Informe o site, como exemplo.com.br.',
            'host.max' => 'Endereço longo demais.',
            'consent.accepted' => 'Confirme que você é o responsável pelo site.',
        ];
    }

    public function host(): string
    {
        return (string) $this->validated('host');
    }
}
