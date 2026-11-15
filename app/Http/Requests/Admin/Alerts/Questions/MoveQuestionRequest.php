<?php

namespace App\Http\Requests\Admin\Alerts\Questions;

use App\Http\Requests\Request;

class MoveQuestionRequest extends Request
{
    /** `direcao=cima` sobe uma posição (-1); qualquer outro valor desce (1). */
    public function direction(): int
    {
        return $this->input('direcao') === 'cima' ? -1 : 1;
    }
}
