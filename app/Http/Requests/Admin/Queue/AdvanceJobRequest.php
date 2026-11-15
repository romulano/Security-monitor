<?php

namespace App\Http\Requests\Admin\Queue;

use App\Http\Requests\Request;

/** Adiantar um job agendado: o id vem na rota (`{job}`, só números); o corpo não leva nada além do CSRF. */
class AdvanceJobRequest extends Request
{
    public function rules(): array
    {
        return [];
    }
}
