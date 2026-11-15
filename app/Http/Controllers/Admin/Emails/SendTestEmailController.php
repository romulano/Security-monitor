<?php

namespace App\Http\Controllers\Admin\Emails;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Emails\SendTestEmailRequest;
use App\Services\Admin\EmailReport;
use Illuminate\Http\RedirectResponse;

class SendTestEmailController extends Controller
{
    public function __construct(private EmailReport $report) {}

    public function __invoke(SendTestEmailRequest $request): RedirectResponse
    {
        $this->report->sendTest($request->validated('to'), $request->user());

        return back()->with('status', 'E-mail de teste na fila. A situação aparece abaixo em instantes.');
    }
}
