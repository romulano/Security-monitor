<?php

namespace App\Http\Controllers\Admin\Alerts\Questions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Questions\ListQuestionsRequest;
use App\Services\Admin\InterviewQuestionManager;
use Illuminate\View\View;

class ListQuestionsController extends Controller
{
    public function __construct(private InterviewQuestionManager $questions) {}

    public function __invoke(ListQuestionsRequest $request): View
    {
        return view('admin.alerts.questions', $this->questions->page($request->editingId(), $request->creating()));
    }
}
