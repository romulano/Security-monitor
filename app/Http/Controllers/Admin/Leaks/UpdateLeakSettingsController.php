<?php

namespace App\Http\Controllers\Admin\Leaks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leaks\UpdateLeakSettingsRequest;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;

class UpdateLeakSettingsController extends Controller
{
    public function __invoke(UpdateLeakSettingsRequest $request): RedirectResponse
    {
        $prefix = (string) $request->string('prefix');
        $deletes = $request->boolean('delete_after_import');

        Settings::setMany([
            'leaks.prefix' => $prefix,
            'leaks.delete_after_import' => $deletes,
        ]);

        return back()->with('status', sprintf(
            'Agora a carga olha %s da pasta de vazamentos, e %s o arquivo depois de carregar.',
            $prefix === '' ? 'a raiz' : "a pasta {$prefix}",
            $deletes ? 'APAGA' : 'mantém',
        ));
    }
}
