<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;

class ConfirmReauthCodeRequest extends Request
{
    // O aviso aparece embaixo do campo do código, na caixa "Confirme que é você".
    protected $errorBag = 'reauth';

    /** "123 456" e "123-456" valem: só os dígitos contam. */
    protected function prepareForValidation(): void
    {
        $code = $this->input('reauth_code');

        if (is_string($code)) {
            $this->merge(['reauth_code' => preg_replace('/\D+/', '', $code)]);
        }
    }

    public function rules(): array
    {
        return ['reauth_code' => ['required', 'digits:6']];
    }

    public function messages(): array
    {
        return [
            'reauth_code.required' => 'Digite o código que chegou no app.',
            'reauth_code.digits' => 'O código tem 6 dígitos.',
        ];
    }

    public function code(): string
    {
        return $this->validated('reauth_code');
    }
}
