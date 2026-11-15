<?php

namespace App\Http\Controllers\Admin\Alerts\Questions;

use App\Http\Controllers\Controller;
use App\Models\InterviewQuestion;
use App\Services\Admin\InterviewQuestionManager;
use Illuminate\Http\RedirectResponse;

class DestroyQuestionController extends Controller
{
    public function __construct(private InterviewQuestionManager $questions) {}

    public function __invoke(InterviewQuestion $question): RedirectResponse
    {
        $this->questions->delete($question);

        return redirect()->route('admin.alerts.questions')->with('status', 'Pergunta apagada.');
    }
}
