<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StorePageViewDurationRequest;
use App\Services\Monitoring\DeviceSightings;
use App\Services\Monitoring\PageViewRecorder;
use Illuminate\Http\Response;

/** Admin → Acessos: o navegador conta quanto tempo a página ficou à vista (e, sem login, o código do aparelho). */
class StorePageViewDurationController extends Controller
{
    public function __construct(private PageViewRecorder $views, private DeviceSightings $devices) {}

    public function __invoke(StorePageViewDurationRequest $request): Response
    {
        $this->views->duration($request->validated('token'), (int) $request->validated('ms'));

        if ($device = $request->device()) {
            $this->devices->record($request->validated('token'), $device);
        }

        return response()->noContent();
    }
}
