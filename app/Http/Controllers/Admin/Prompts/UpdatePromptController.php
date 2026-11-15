<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Prompts\UpdatePromptRequest;
use App\Services\Admin\PromptManager;
use Illuminate\Http\RedirectResponse;

class UpdatePromptController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(UpdatePromptRequest $request, string $part): RedirectResponse
    {
        $message = $this->prompts->save($part, $request->body(), $request->user());

        return redirect()->to(route('admin.prompts.show', $this->prompts->stageOf($part)).'#'.$part)->with('status', $message);
    }
}
