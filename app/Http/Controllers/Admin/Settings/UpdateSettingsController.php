<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\UpdateSettingsRequest;
use App\Services\Admin\SettingsEditor;
use Illuminate\Http\RedirectResponse;

class UpdateSettingsController extends Controller
{
    public function __construct(private SettingsEditor $settings) {}

    public function __invoke(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->validated());

        return back()->with('status', 'Configurações salvas.');
    }
}
