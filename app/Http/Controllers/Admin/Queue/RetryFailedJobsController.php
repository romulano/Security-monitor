<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Devolve todos os jobs falhos à fila. */
class RetryFailedJobsController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(): RedirectResponse
    {
        return back()->with('status', $this->queue->retryAll());
    }
}
