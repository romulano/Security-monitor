<?php

namespace App\Http\Controllers\Admin\Alerts\CustomAnswers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\CustomAnswers\UpdateCustomAnswerRequest;
use App\Models\CustomAnswerTerm;
use App\Services\Admin\CustomAnswerManager;
use Illuminate\Http\RedirectResponse;

class UpdateCustomAnswerController extends Controller
{
    public function __construct(private CustomAnswerManager $answers) {}

    public function __invoke(UpdateCustomAnswerRequest $request, CustomAnswerTerm $answer): RedirectResponse
    {
        $this->answers->update($answer, $request->values());

        return back()->with('status', "Termos de “{$answer->label}” salvos; os perfis que usam foram recompilados.");
    }
}
