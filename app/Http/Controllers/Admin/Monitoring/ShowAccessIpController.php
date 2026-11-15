<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Monitoring\ShowAccessIpRequest;
use App\Services\Admin\AccessEraser;
use App\Services\Admin\DeviceLinks;
use App\Services\Admin\IpTimeline;
use App\Services\Monitoring\OwnAddresses;
use App\Services\Monitoring\PageViewRecorder;
use Illuminate\View\View;

class ShowAccessIpController extends Controller
{
    public function __construct(
        private IpTimeline $report,
        private DeviceLinks $devices,
        private PageViewRecorder $views,
        private AccessEraser $eraser,
        private OwnAddresses $own,
    ) {}

    public function __invoke(ShowAccessIpRequest $request): View
    {
        $ip = $request->validated('ip');

        return view('admin.accesses.ip', $this->report->show($request->validated()) + [
            'enabled' => $this->views->enabled(),
            // O mesmo aparelho em outros IPs (só segurança, nunca a conta).
            'devices' => $this->devices->forIp($ip),
            // A zona de perigo: quantos registros o IP tem guardados (o diálogo diz) e se ele já conta como nosso.
            'stored' => $this->eraser->countByIp([$ip])[$ip] ?? ['views' => 0, 'events' => 0, 'devices' => 0],
            'ours' => $this->own->contains($ip),
        ]);
    }
}
