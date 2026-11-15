<?php

namespace App\Http\Controllers\Alerts\Github;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Github\UpdateGithubInterviewStepRequest;
use App\Services\Github\GithubInterview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class UpdateGithubInterviewStepController extends Controller
{
    public function __construct(private GithubInterview $interview) {}

    public function __invoke(UpdateGithubInterviewStepRequest $request, int $passo): RedirectResponse
    {
        $monitor = $request->monitor();
        $here = fn () => route('alerts.github.interview', $monitor?->urlParams(['passo' => $passo]) ?? ['passo' => $passo]);

        try {
            $monitor ? $this->interview->save($monitor, $passo, $request->answers())
                : $monitor = $this->interview->startWith($request->user(), $request->answers());
        } catch (ValidationException $e) {
            // Volta com o que foi digitado, menos o que tinha cara de segredo.
            return redirect()->to($here())->withErrors($e->errors())->withInput(['answers' => $request->answers(), ]);
        }

        if ($removed = $request->removedSecrets()) {
            // O segredo nunca volta à tela: o cliente fica no passo, com o aviso.
            return redirect()->to($here())->with('error', GithubInterview::secretWarning($removed));
        }

        return redirect()->route('alerts.github.interview', $monitor->urlParams(['passo' => $passo + 1]));
    }
}
