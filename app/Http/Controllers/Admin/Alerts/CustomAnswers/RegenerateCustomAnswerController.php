<?php

namespace App\Http\Controllers\Admin\Alerts\CustomAnswers;

use App\Http\Controllers\Controller;
use App\Models\CustomAnswerTerm;
use App\Services\Admin\CustomAnswerManager;
use Illuminate\Http\RedirectResponse;

class RegenerateCustomAnswerController extends Controller
{
    public function __construct(private CustomAnswerManager $answers) {}

    public function __invoke(CustomAnswerTerm $answer): RedirectResponse
    {
        $this->answers->regenerate($answer);

        return back()->with('status', "A IA vai gerar de novo os termos de “{$answer->label}”.");
    }
}
