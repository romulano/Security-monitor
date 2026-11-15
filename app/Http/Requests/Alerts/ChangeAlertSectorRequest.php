<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Concerns\TargetsAlertProfile;
use App\Http\Requests\Request;
use App\Models\AlertProfile;

/** Trocar o setor do alerta de empresa: quem o configura, pelo `?alerta=`; senão, 404. O destino, o serviço confere. */
class ChangeAlertSectorRequest extends Request
{
    use TargetsAlertProfile;

    private ?AlertProfile $profile = null;

    public function authorize(): bool
    {
        return $this->profile()?->company_id !== null;
    }

    public function rules(): array
    {
        // "sem": sem setor (o select é obrigatório, então a opção não pode ter valor vazio), como no Radar.
        return ['setor' => ['required', 'regex:/^(sem|[1-9]\d{0,17})$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    public function profile(): ?AlertProfile
    {
        return $this->profile ??= $this->alertProfileOf($this->user());
    }

    /** O setor novo (nulo, de "sem": sem setor, a empresa toda vê; só quem administra a empresa escolhe). */
    public function sectorId(): ?int
    {
        return $this->validated('setor') === 'sem' ? null : (int) $this->validated('setor');
    }
}
