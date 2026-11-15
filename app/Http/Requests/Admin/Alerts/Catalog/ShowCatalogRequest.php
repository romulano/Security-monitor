<?php

namespace App\Http\Requests\Admin\Alerts\Catalog;

use App\Http\Requests\Request;
use App\Models\TechCatalog;
use Illuminate\Validation\Rule;

class ShowCatalogRequest extends Request
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'categoria' => ['nullable', Rule::in(array_keys(TechCatalog::CATEGORIES))],
            'origem' => ['nullable', 'in:discovery'],
        ];
    }

    /** Busca (`q`), categoria (`categoria`) e origem (`origem=discovery`: as da descoberta automática). */
    public function filters(): array
    {
        return $this->validated();
    }

    /** `?editar=ID`: a tecnologia aberta no formulário de edição. */
    public function editingId(): ?int
    {
        return $this->integer('editar') ?: null;
    }

    /** `?nova=1`: abre o formulário de nova tecnologia. */
    public function creating(): bool
    {
        return $this->boolean('nova');
    }
}
