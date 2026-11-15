<?php

namespace App\Http\Controllers\Admin\Alerts\Questions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Questions\MoveQuestionRequest;
use App\Models\InterviewQuestion;
use App\Services\Admin\InterviewQuestionManager;
use Illuminate\Http\RedirectResponse;

class MoveQuestionController extends Controller
{
    public function __construct(private InterviewQuestionManager $questions) {}

    public function __invoke(MoveQuestionRequest $request, InterviewQuestion $question): RedirectResponse
    {
        $this->questions->move($question, $request->direction());

        return back();
    }
}
