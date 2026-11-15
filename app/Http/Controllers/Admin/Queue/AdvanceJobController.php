<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Queue\AdvanceJobRequest;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Adianta um job agendado: o worker da fila dele o pega na próxima volta. */
class AdvanceJobController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(AdvanceJobRequest $request, int $job): RedirectResponse
    {
        return back()->with('status', $this->queue->advance($job));
    }
}
