<?php

namespace App\Http\Requests\Alerts;

use App\Http\Requests\Request;

/** A avaliação de um alerta: o voto, o motivo opcional e o "mas não nos atinge". */
abstract class FeedbackRequest extends Request
{
    public function rules(): array
    {
        return [
            'feedback' => ['required', 'in:relevant,irrelevant'],
            'note' => ['nullable', 'string'],
            'unaffected' => ['nullable', 'boolean'],
        ];
    }

    /** `relevant` ou `irrelevant`. */
    public function feedback(): string
    {
        return $this->validated('feedback');
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }

    /** "Mas não nos atinge" (só vale junto com "Relevante"). */
    public function unaffected(): bool
    {
        return $this->boolean('unaffected');
    }
}
