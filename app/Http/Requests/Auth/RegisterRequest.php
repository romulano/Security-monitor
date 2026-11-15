<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Request;
use App\Rules\EmailNotTaken;
use App\Services\Auth\RejectedAttempts;
use App\Support\Turnstile;
use Illuminate\Contracts\Validation\Validator;

class RegisterRequest extends Request
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', new EmailNotTaken],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'E-mail inválido.',
            'email.max' => 'O e-mail pode ter no máximo 255 caracteres.',
            'password.required' => 'Informe a senha.',
            'password.min' => 'A senha precisa ter pelo menos 8 caracteres.',
            'password.max' => 'A senha pode ter no máximo 255 caracteres.',
            'password.confirmed' => 'A confirmação não bate com a senha.',
        ];
    }

    protected function passedValidation(): void
    {
        Turnstile::verify($this, 'cadastro');
    }

    /** O e-mail que já tem conta: a tentativa fica registrada (só o código do e-mail) e a resposta segue igual. */
    protected function failedValidation(Validator $validator): void
    {
        if (isset($validator->failed()['email'][EmailNotTaken::class])) {
            app(RejectedAttempts::class)->record($this, 'cadastro_email_existente', is_string($email = $this->input('email')) ? $email : null);
        }

        parent::failedValidation($validator);
    }
}
