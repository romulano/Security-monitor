<?php

namespace App\Http\Controllers\Admin\Alerts\Questions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Questions\StoreQuestionRequest;
use App\Services\Admin\InterviewQuestionManager;
use Illuminate\Http\RedirectResponse;

class StoreQuestionController extends Controller
{
    public function __construct(private InterviewQuestionManager $questions) {}

    public function __invoke(StoreQuestionRequest $request): RedirectResponse
    {
        $question = $this->questions->create($request->fields());

        return redirect()->route('admin.alerts.questions', ['editar' => $question->id])->with('status', 'Pergunta criada.');
    }
}
