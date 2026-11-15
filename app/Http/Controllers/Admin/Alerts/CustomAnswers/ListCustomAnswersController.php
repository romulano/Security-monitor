<?php

namespace App\Http\Controllers\Admin\Alerts\CustomAnswers;

use App\Http\Controllers\Controller;
use App\Services\Admin\CustomAnswerManager;
use Illuminate\View\View;

class ListCustomAnswersController extends Controller
{
    public function __construct(private CustomAnswerManager $answers) {}

    public function __invoke(): View
    {
        return view('admin.alerts.custom-answers', $this->answers->page());
    }
}
