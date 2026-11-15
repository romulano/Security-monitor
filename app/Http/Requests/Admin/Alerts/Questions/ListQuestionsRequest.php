<?php

namespace App\Http\Requests\Admin\Alerts\Questions;

use App\Http\Requests\Request;

class ListQuestionsRequest extends Request
{
    /** `?editar=ID`: a pergunta aberta no formulário de edição. */
    public function editingId(): ?int
    {
        return $this->integer('editar') ?: null;
    }

    /** `?nova=1`: abre o formulário de nova pergunta. */
    public function creating(): bool
    {
        return $this->boolean('nova');
    }
}
