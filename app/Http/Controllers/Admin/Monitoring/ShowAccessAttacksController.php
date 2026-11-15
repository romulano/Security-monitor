<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessAttacksRequest;
use App\Services\Admin\AttackReport;
use App\Services\Admin\DeviceLinks;
use App\Services\Monitoring\PageViewRecorder;
use Illuminate\View\View;

class ShowAccessAttacksController extends Controller
{
    public function __construct(private AttackReport $report, private DeviceLinks $devices, private PageViewRecorder $views) {}

    public function __invoke(ShowAccessAttacksRequest $request): View
    {
        $data = $this->report->overview($request->validated());

        // Os aparelhos dos IPs da tela que trocaram de IP; os nossos ficam de fora.
        return view('admin.accesses.attacks', $data + ['enabled' => $this->views->enabled(), 'mais' => $request->validated('mais')]
            + $this->devices->forAttackers($data['byIp']->reject(fn (object $row) => $row->ours)->pluck('ip')->values()->all()));
    }
}
