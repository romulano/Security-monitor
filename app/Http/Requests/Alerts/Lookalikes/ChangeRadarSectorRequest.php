<?php

namespace App\Http\Requests\Alerts\Lookalikes;

/** Trocar o setor do Radar de empresa: quem o configura, pelo `?radar=`; senão, 404. O destino, o serviço confere. */
class ChangeRadarSectorRequest extends RadarPageRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->watch()?->company_id !== null && $this->managesWatch();
    }

    public function rules(): array
    {
        // "sem": sem setor (o select é obrigatório, então a opção não pode ter valor vazio).
        return ['setor' => ['required', 'regex:/^(sem|[1-9]\d{0,17})$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    /** O setor novo (nulo, de "sem": sem setor, a empresa toda vê; só quem administra a empresa escolhe). */
    public function sectorId(): ?int
    {
        return $this->validated('setor') === 'sem' ? null : (int) $this->validated('setor');
    }
}
