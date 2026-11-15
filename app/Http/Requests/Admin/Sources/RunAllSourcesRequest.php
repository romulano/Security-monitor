<?php

namespace App\Http\Requests\Admin\Sources;

use App\Http\Requests\Request;

class RunAllSourcesRequest extends Request
{
    /** Com `force`, roda todas as fontes ligadas, mesmo fora do intervalo de cada uma. */
    public function force(): bool
    {
        return $this->boolean('force');
    }
}
