<?php

namespace App\Http\Controllers\Admin\Emails;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Emails\ShowEmailsRequest;
use App\Services\Admin\EmailReport;
use Illuminate\View\View;

class ShowEmailsController extends Controller
{
    public function __construct(private EmailReport $report) {}

    public function __invoke(ShowEmailsRequest $request): View
    {
        return view('admin.emails', $this->report->overview((bool) $request->validated('erros')));
    }
}
