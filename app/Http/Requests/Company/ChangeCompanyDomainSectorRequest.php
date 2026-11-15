<?php

namespace App\Http\Requests\Company;

/** Trocar o setor de um domínio da empresa: quem o configura; o destino, o serviço confere (ItemSectors). */
class ChangeCompanyDomainSectorRequest extends VerifyCompanyDomainRequest
{
    public function rules(): array
    {
        // "sem": sem setor (o select é obrigatório, então a opção não pode ter valor vazio).
        return ['setor' => ['required', 'regex:/^(sem|[1-9]\d{0,17})$/']];
    }

    public function messages(): array
    {
        return ['setor.*' => 'Escolha um setor da lista.'];
    }

    public function sectorId(): ?int
    {
        return $this->validated('setor') === 'sem' ? null : (int) $this->validated('setor');
    }
}
