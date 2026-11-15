<?php

namespace App\Http\Requests\Alerts\Interview;

use App\Services\Alerts\InterviewService;

class UpdateInterviewStepRequest extends InterviewRequest
{
    /** Só os passos com perguntas são gravados; a revisão (o último) é 404. Alerta novo de empresa: o passo 1, de quem cria. */
    public function authorize(): bool
    {
        return $this->step() >= 1 && $this->step() < InterviewService::LAST_STEP && (! $this->startsNew() || $this->mayStartNew());
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }

    /** As respostas do passo, como vieram; o service valida pergunta a pergunta. */
    public function answers(): array
    {
        return (array) $this->input('answers', []);
    }

    /** Aceite do termo da descoberta (passo do domínio). */
    public function consent(): bool
    {
        return $this->boolean('consent');
    }

    /** "Salvar" no passo do domínio: o cliente fica na tela para ver o TXT e o resultado. */
    public function stays(): bool
    {
        return $this->input('acao') === 'ficar';
    }
}
