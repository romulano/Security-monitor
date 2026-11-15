<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Devolve um job falho à fila. */
class RetryFailedJobController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(string $uuid): RedirectResponse
    {
        return back()->with('status', $this->queue->retry($uuid));
    }
}
