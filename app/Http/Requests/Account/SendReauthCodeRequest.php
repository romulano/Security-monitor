<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Request;
use App\Services\Pentest\PentestAccess;
use App\Services\Pentest\PentestAvailability;
use App\Services\Pentest\PentestWizard;
use App\Support\DomainName;

class SendReauthCodeRequest extends Request
{
    /**
     * Só um app da própria conta (o de outra conta dá 404). Se ele pode receber o código, quem decide é o ReauthCodes.
     * O que não é número fica para a validação (422): no Postgres, texto comparado ao id inteiro é erro 500.
     * O domínio que o código nomeia, se vier, também é só um da conta.
     */
    public function authorize(): bool
    {
        if ($this->filled('dominio') && $this->context() === null) {
            return false;
        }

        $channel = $this->input('channel');

        if ((! is_string($channel) && ! is_int($channel)) || ! ctype_digit((string) $channel)) {
            return true;
        }

        return $this->user()->notificationChannels()->whereKey((int) $channel)->exists();
    }

    public function rules(): array
    {
        return ['channel' => ['required', 'integer'], 'dominio' => ['nullable', 'string', 'max:253']];
    }

    public function messages(): array
    {
        return [
            'channel.required' => 'Escolha o app que recebe o código.',
            'channel.integer' => 'Escolha o app que recebe o código.',
        ];
    }

    public function channelId(): int
    {
        return (int) $this->validated('channel');
    }

    /**
     * O domínio que o código nomeia (Q6 do Blue Team): a página do passo 7 da
     * autorização de um teste manda o dela. Só vale o domínio desta conta que pode
     * ser autorizado agora: chegou ao passo 7 e tem a posse comprovada (A2 do Red
     * Team). Um nome digitado, sem posse, nunca vai à mensagem oficial do Security Monitor.
     *
     * E só para quem pode autorizar um teste (L1 do Red Team): os testes existem
     * (PENTEST_ALLOWED) e a conta tem a liberação do admin. Fora disso, o domínio
     * pedido dá 404, como o de outra conta, e nenhuma mensagem sai. Sem o domínio, o
     * código de sempre continua para qualquer conta.
     */
    public function context(): ?string
    {
        $user = $this->user();

        if (! PentestAvailability::for($user) || ! PentestAccess::granted($user)) {
            return null;
        }

        $name = $this->input('dominio');

        if (! is_string($name) || strlen($name) > 253 || ! DomainName::isValid($name)) {
            return null;
        }

        $domain = $user->pentestDomains()->with('domain')->where('host', $name)->first();

        return $domain && PentestWizard::reachable($domain) === PentestWizard::LAST_STEP && $domain->ownershipHolds() ? $name : null;
    }
}
