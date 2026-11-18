<?php

namespace App\Http\Requests\Admin\Leaks;

use App\Http\Requests\Request;
use Closure;
use Illuminate\Validation\Rule;

/** O admin cadastra um domínio (ou uma marca) de e-mail genérico. */
class StoreFreeMailRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge(['value' => mb_strtolower(trim((string) $this->input('value')))]);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:domain,brand,prefix'],
            'value' => [
                'required', 'string', 'max:253',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $value = (string) $value;

                    if ($this->input('kind') === 'domain' && preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?\.[a-z]{2,}$/', $value) !== 1) {
                        $fail('Informe o domínio completo, como gmail.com.');
                    }

                    if ($this->input('kind') === 'brand' && preg_match('/^[a-z0-9][a-z0-9\-]*$/', $value) !== 1) {
                        $fail('A marca é um nome só, sem ponto: hotmail, e não hotmail.com.');
                    }

                    // O ponto no fim não é enfeite: sem ele, um prefixo `mail` pegaria mailchimp.com.
                    if ($this->input('kind') === 'prefix' && preg_match('/^[a-z0-9][a-z0-9.\-]*\.$/', $value) !== 1) {
                        $fail('O prefixo tem de terminar em ponto: gmail. — assim ele pega gmail.qualquercoisa sem pegar gmailxpto.com.');
                    }
                },
                Rule::unique('free_mail_domains', 'value')->where(fn ($query) => $query->where('kind', $this->input('kind'))),
            ],
            'note' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return ['kind' => 'natureza', 'value' => 'domínio', 'note' => 'observação'];
    }

    public function messages(): array
    {
        return ['value.unique' => 'Esse já está na lista.'];
    }

    /** @return array{kind:string,value:string,note:?string} */
    public function entry(): array
    {
        return [
            'kind' => (string) $this->string('kind'),
            'value' => (string) $this->string('value'),
            'note' => $this->filled('note') ? (string) $this->string('note') : null,
        ];
    }
}
