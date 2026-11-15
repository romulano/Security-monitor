<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\Admin\SettingsEditor;
use Illuminate\View\View;

class ShowSettingsController extends Controller
{
    public function __construct(private SettingsEditor $settings) {}

    public function __invoke(): View
    {
        return view('admin.settings', $this->settings->page());
    }
}
