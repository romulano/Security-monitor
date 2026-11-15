<?php

namespace App\Http\Controllers\Admin\Security;

use App\Http\Controllers\Controller;
use App\Services\Admin\SecurityOverview;
use Illuminate\View\View;

class ListSecuritySitesController extends Controller
{
    public function __construct(private SecurityOverview $overview) {}

    public function __invoke(): View
    {
        return view('admin.security.index', $this->overview->index());
    }
}
