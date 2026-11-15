<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Prompts\ResetPromptRequest;
use App\Services\Admin\PromptManager;
use Illuminate\Http\RedirectResponse;

class ResetPromptController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(ResetPromptRequest $request, string $part): RedirectResponse
    {
        $message = $this->prompts->reset($part, $request->user());

        return redirect()->to(route('admin.prompts.show', $this->prompts->stageOf($part)).'#'.$part)->with('status', $message);
    }
}
