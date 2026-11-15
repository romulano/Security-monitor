<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Request;

class ListNewsUpdatesRequest extends Request
{
    /** O service limita entre 1 e 50. */
    public function limit(): int
    {
        return (int) $this->query('limit', 8);
    }
}
