<?php

namespace App\Http\Requests\Alerts\Github;

/** Trocar o setor do monitoramento de empresa: quem o configura, pelo `?monitor=`; senão, 404. O destino, o serviço confere. */
class ChangeGithubSectorRequest extends GithubRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->monitor()?->company_id !== null && $this->managesMonitor();
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
