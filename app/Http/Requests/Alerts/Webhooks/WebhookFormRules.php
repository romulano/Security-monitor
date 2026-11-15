<?php

namespace App\Http\Requests\Alerts\Webhooks;

use App\Services\Alerts\Webhook\WebhookSecret;
use App\Services\Alerts\Webhook\WebhookUrl;
use Closure;
use InvalidArgumentException;

/** As regras do formulário do webhook (nome, URL e autenticação), iguais no cadastro e na troca. */
trait WebhookFormRules
{
    /** `$keepUrl`: na troca, a URL em branco fica como está (a tela não mostra a URL inteira, que pode levar um token). */
    protected function endpointRules(bool $keepUrl = false): array
    {
        return [
            'nome' => ['required', 'string', 'max:60'],
            'url' => [$keepUrl ? 'nullable' : 'required', 'string', 'max:'.WebhookUrl::MAX, function (string $attribute, mixed $value, Closure $fail) {
                try {
                    WebhookUrl::normalize((string) $value);
                } catch (InvalidArgumentException $e) {
                    $fail($e->getMessage());
                }
            }],
        ];
    }

    protected function authRules(): array
    {
        return [
            'autenticacao' => ['required', 'in:gerar,escolher,nenhuma'],
            'segredo' => ['nullable', 'required_if:autenticacao,escolher', 'string', 'max:'.WebhookSecret::MAX, function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('autenticacao') === 'escolher' && ($problem = WebhookSecret::problem(trim((string) $value)))) {
                    $fail($problem);
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Dê um nome ao webhook, como “Produção” ou “SIEM”.',
            'url.required' => 'Informe a URL do webhook.',
            'segredo.required_if' => 'Escreva o valor do segredo, ou peça para o Security Monitor gerar um.',
        ];
    }
}
