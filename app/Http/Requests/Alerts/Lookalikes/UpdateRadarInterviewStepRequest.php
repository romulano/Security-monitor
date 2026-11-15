<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Services\Lookalike\RadarInterview;

class UpdateRadarInterviewStepRequest extends RadarInterviewRequest
{
    /** Gravam o passo 1 (domínio) e o 2 (marca); o 3 é o "Ativar". Sem Radar, só o passo 1. */
    public function authorize(): bool
    {
        return parent::authorize()
            && $this->step() >= 1 && $this->step() < RadarInterview::LAST_STEP
            && ($this->step() === 1 || $this->watch() !== null);
    }

    public function rules(): array
    {
        return $this->step() === 1
            ? [
                'origem' => ['required', 'string', 'max:20'],
                'outro' => ['nullable', 'string', 'max:255', 'required_if:origem,outro'],
                // O setor do Radar novo (conta de empresa); quem pode usar qual, o Visibility confere ao criar.
            ]
            : [
                'empresa' => ['required', 'string', 'max:120'],
                'marcas' => ['nullable', 'array', 'max:20'],
                'marcas.*' => ['nullable', 'string', 'max:60'],
            ];
    }

    public function messages(): array
    {
        return [
            'origem.required' => 'Escolha um dos domínios da lista ou escreva outro.',
            'outro.required_if' => 'Escreva o domínio, como empresa.com.br.',
            'outro.max' => 'Use no máximo 255 caracteres.',
            'empresa.required' => 'Informe o nome da empresa.',
            'empresa.max' => 'Use no máximo 120 caracteres.',
            'marcas.*.max' => 'Cada nome pode ter até 60 caracteres.',
            'setor.*' => 'Escolha um setor da lista.',
        ];
    }

    /** O domínio já comprovado em notícias que o cliente escolheu (nulo: escreveu outro). */
    public function provenId(): ?int
    {
        return ctype_digit((string) $this->input('origem')) ? (int) $this->input('origem') : null;
    }

    public function typed(): ?string
    {
        return $this->provenId() ? null : $this->string('outro')->toString();
    }

    public function company(): string
    {
        return $this->string('empresa')->trim()->toString();
    }

    /** @return list<string> */
    public function brands(): array
    {
        return array_values(array_map('strval', (array) $this->input('marcas', [])));
    }
}
