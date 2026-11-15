<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;

/** Botão do aviso no Slack, Discord ou Telegram: a assinatura da rota substitui o login. */
class SignedLookalikeVerdictRequest extends Request
{
    /** Só as três respostas dos botões (desfazer, só na tela do Radar). */
    public function authorize(): bool
    {
        return in_array($this->route('acao'), ['nosso', 'seguro', 'golpe'], true);
    }
}
