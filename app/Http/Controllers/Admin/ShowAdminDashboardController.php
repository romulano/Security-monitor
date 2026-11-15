<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboard;
use Illuminate\View\View;

class ShowAdminDashboardController extends Controller
{
    public function __construct(private AdminDashboard $dashboard) {}

    public function __invoke(): View
    {
        return view('admin.dashboard', $this->dashboard->page());
    }
}
