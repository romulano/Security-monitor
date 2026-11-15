<?php

namespace App\Http\Requests\Admin\Alerts\CustomAnswers;

use App\Http\Requests\Request;

class UpdateCustomAnswerRequest extends Request
{
    public function rules(): array
    {
        return ['values' => ['required', 'string', 'max:2000']];
    }

    public function messages(): array
    {
        return ['values.required' => 'Informe ao menos um termo.'];
    }

    /** Os termos (ou códigos de país) separados por vírgula, como o admin digitou. */
    public function values(): string
    {
        return $this->validated('values');
    }
}
