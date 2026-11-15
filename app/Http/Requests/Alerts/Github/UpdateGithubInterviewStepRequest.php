<?php

namespace App\Http\Requests\Alerts\Github;

use App\Services\Github\GithubInterview;

class UpdateGithubInterviewStepRequest extends GithubInterviewRequest
{
    private ?array $cleaned = null;

    /** Só os passos com perguntas são gravados; a revisão (o último) é 404. Monitoramento novo, só pelo passo 1. */
    public function authorize(): bool
    {
        return $this->step() >= 1 && $this->step() < GithubInterview::LAST_STEP && parent::authorize()
            && ($this->monitor() !== null || $this->step() === 1);
    }

    public function rules(): array
    {
        // O setor do monitoramento novo de empresa; quem pode usar qual, o Visibility confere ao criar.
        return ['setor' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    /** O setor escolhido para o monitoramento novo (nulo: sem setor, só quem administra a empresa). */
    public function sectorId(): ?int
    {
        return $this->filled('setor') ? (int) $this->validated('setor') : null;
    }

    /** As respostas do passo, sem o que tem cara de valor de chave (o service valida o resto). */
    public function answers(): array
    {
        return $this->cleaned()['answers'];
    }

    /** Quantos itens foram tirados por parecerem segredo. */
    public function removedSecrets(): int
    {
        return $this->cleaned()['removed'];
    }

    private function cleaned(): array
    {
        return $this->cleaned ??= GithubInterview::withoutSecrets((array) $this->input('answers', []));
    }
}
