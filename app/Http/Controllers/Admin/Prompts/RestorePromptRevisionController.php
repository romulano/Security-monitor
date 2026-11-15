<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Prompts\RestorePromptRevisionRequest;
use App\Models\PromptRevision;
use App\Services\Admin\PromptManager;
use Illuminate\Http\RedirectResponse;

class RestorePromptRevisionController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(RestorePromptRevisionRequest $request, PromptRevision $revision): RedirectResponse
    {
        $message = $this->prompts->restore($revision, $request->user());

        return redirect()->to(route('admin.prompts.show', $this->prompts->stageOf($revision->key)).'#'.$revision->key)->with('status', $message);
    }
}
