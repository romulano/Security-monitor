<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;
use App\Models\AlertProfile;

class StoreAlertRequest extends Request
{
    public function rules(): array
    {
        return ['setor' => ['nullable', 'regex:/^[1-9]\d{0,17}$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    /**
     * `copiar`: um alerta que a conta configura (404 se não), cujas respostas o novo herda. Configurar, não só ver: o
     * responsável não leva os termos e as marcas de outro setor para o dele.
     */
    public function copyFrom(): ?AlertProfile
    {
        return $this->filled('copiar') ? AlertProfile::query()->manageableBy($this->user())->findOrFail($this->integer('copiar')) : null;
    }

    /** O setor do alerta de empresa (sector-choice); vazio = sem setor, que só a admin escolhe (o serviço confere). */
    public function sectorId(): ?int
    {
        return $this->filled('setor') ? (int) $this->validated('setor') : null;
    }
}
