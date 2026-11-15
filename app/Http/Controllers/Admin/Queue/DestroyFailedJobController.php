<?php

namespace App\Http\Controllers\Admin\Queue;

use App\Http\Controllers\Controller;
use App\Services\Admin\QueueMonitor;
use Illuminate\Http\RedirectResponse;

/** Apaga um job da lista de falhos. */
class DestroyFailedJobController extends Controller
{
    public function __construct(private QueueMonitor $queue) {}

    public function __invoke(string $uuid): RedirectResponse
    {
        return back()->with('status', $this->queue->forget($uuid));
    }
}
