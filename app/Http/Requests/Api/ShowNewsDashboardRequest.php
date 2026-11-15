<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\PicksDashboardWindow;
use App\Http\Requests\Request;

class ShowNewsDashboardRequest extends Request
{
    use PicksDashboardWindow;
}
