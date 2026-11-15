<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Queue\ShowQueueRequest;
use App\Services\Admin\QueueMonitor;
use Illuminate\View\View;

class ShowQueueController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(ShowQueueRequest $request): View
    {
        $tab = $request->validated('ver') ?? 'fila';

        return view('admin.queue', [
            'tab' => $tab,
            'jobs' => $tab === 'falhos' ? $this->queue->failed() : $this->queue->queued(),
            'counts' => $this->queue->counts(),
            'advanceable' => $tab === 'fila' ? $this->queue->advanceable() : null,
        ]);
    }
}
