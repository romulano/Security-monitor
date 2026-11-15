<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Apaga a lista inteira de jobs falhos. */
class DestroyFailedJobsController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(): RedirectResponse
    {
        return back()->with('status', $this->queue->forgetAll());
    }
}
