<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;

/** "Adicionar o meu IP atual": o IP de quem está no admin, o mesmo que o acesso grava (X-Forwarded-For da borda). */
class AddMyAddressRequest extends Request
{
    public function address(): string
    {
        return (string) $this->ip();
    }
}
