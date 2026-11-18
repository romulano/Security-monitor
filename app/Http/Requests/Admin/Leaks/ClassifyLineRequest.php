<?php

namespace App\Http\Requests\Admin\Leaks;

use App\Http\Requests\Request;

/** O admin diz quem é login, quem é senha e qual o endereço de uma linha da quarentena. */
class ClassifyLineRequest extends Request
{
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:320'],
            'password' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => 'login', 'password' => 'senha', 'url' => 'endereço'];
    }

    /** @return array{login:string,password:string,url:?string} */
    public function decision(): array
    {
        return [
            'login' => (string) $this->string('login'),
            'password' => (string) $this->string('password'),
            'url' => $this->filled('url') ? (string) $this->string('url') : null,
        ];
    }
}
