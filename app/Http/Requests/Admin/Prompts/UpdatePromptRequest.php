<?php

namespace App\Http\Requests\Admin\Prompts;

use App\Http\Requests\Request;
use App\Services\Prompts\PromptCatalog;
use Illuminate\Validation\Validator;

class UpdatePromptRequest extends Request
{
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:30000']];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'O prompt não pode ficar vazio. Para voltar ao texto padrão, use "Restaurar padrão".',
            'body.max' => 'O prompt passou de 30 mil caracteres.',
        ];
    }

    /** Trecho com variável ({label}): sem ela, a resposta do cliente não teria onde entrar. */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (PromptCatalog::PARTS[(string) $this->route('part')]['vars'] ?? [] as $var => $meaning) {
                if (! str_contains((string) $this->input('body'), '{'.$var.'}')) {
                    $validator->errors()->add('body', "O texto precisa ter {{$var}}: é onde entra {$meaning}.");
                }
            }
        }];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
