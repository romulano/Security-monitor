<?php

namespace App\Rules;

use App\Models\User;
use App\Services\Auth\Registration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Um e-mail, uma conta, sem diferença de caixa: "Ana@x" ao lado de "ana@x" seria outra conta (e a de empresa ficaria duplicada). */
class EmailNotTaken implements ValidationRule
{
    /** @param  int|null  $ignore  a própria conta, na troca de e-mail (mudar só a caixa do próprio e-mail vale) */
    public function __construct(private ?int $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::query()->whereRaw('lower(email) = lower(?)', [$value])->when($this->ignore, fn ($query) => $query->whereKeyNot($this->ignore))->exists()) {
            $fail(Registration::EMAIL_TAKEN);
        }
    }
}
