<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Queue\AdvanceScheduledJobsRequest;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Adianta todos os jobs agendados que o botão cobre. */
class AdvanceScheduledJobsController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(AdvanceScheduledJobsRequest $request): RedirectResponse
    {
        return back()->with('status', $this->queue->advanceAll());
    }
}
