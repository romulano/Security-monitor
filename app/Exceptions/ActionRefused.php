<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Pedido recusado por uma regra do negócio (limite atingido, canal por
 * reconectar, varredura já feita hoje). O service lança com a mensagem para o
 * usuário e a resposta volta à página anterior (ou a `$redirectTo`) com o
 * aviso em `error`. Não é falha: não vai para o log.
 */
class ActionRefused extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly ?string $redirectTo = null)
    {
        parent::__construct($message);
    }

    public function render(): RedirectResponse
    {
        return ($this->redirectTo ? redirect($this->redirectTo) : back())->with('error', $this->getMessage());
    }
}
