<?php

namespace App\Http\Requests\Admin\Queue;

use App\Http\Requests\Request;

/** Adiantar todos os agendados: o corpo não leva nada além do CSRF. */
class AdvanceScheduledJobsRequest extends Request
{
    public function rules(): array
    {
        return [];
    }
}
