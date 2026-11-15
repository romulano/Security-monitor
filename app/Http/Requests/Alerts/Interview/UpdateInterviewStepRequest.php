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
        return ['setor' => ['nullable', 'regex:/^[1-9]\d{0,17}$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    /** O setor do alerta novo de empresa (sector-choice); vazio = sem setor, que só a admin escolhe (o serviço confere). */
    public function sectorId(): ?int
    {
        return $this->filled('setor') ? (int) $this->validated('setor') : null;
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
