<?php

namespace App\Http\Controllers\Admin\Alerts\Questions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Questions\UpdateQuestionRequest;
use App\Models\InterviewQuestion;
use App\Services\Admin\InterviewQuestionManager;
use Illuminate\Http\RedirectResponse;

class UpdateQuestionController extends Controller
{
    public function __construct(private InterviewQuestionManager $questions) {}

    public function __invoke(UpdateQuestionRequest $request, InterviewQuestion $question): RedirectResponse
    {
        $this->questions->update($question, $request->fields());

        return back()->with('status', 'Pergunta salva. Vale para quem abrir a entrevista a partir de agora.');
    }
}
