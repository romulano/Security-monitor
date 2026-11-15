<?php

namespace App\Http\Requests\Admin\Logbook;

use App\Http\Requests\Request;

/** As regras do registro do diário de bordo, iguais para criar e para editar. */
abstract class LogEntryRequest extends Request
{
    public function rules(): array
    {
        return [
            'logged_on' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'logged_on.required' => 'Informe a data do registro.',
            'logged_on.date_format' => 'Data inválida.',
            'title.required' => 'Informe o título do registro.',
            'title.max' => 'O título pode ter no máximo 120 caracteres.',
            'text.required' => 'Escreva o que mudou.',
            'text.max' => 'O texto pode ter no máximo 600 caracteres.',
        ];
    }
}
