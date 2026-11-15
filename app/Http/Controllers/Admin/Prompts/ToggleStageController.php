<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Services\Admin\PromptManager;
use Illuminate\Http\RedirectResponse;

class ToggleStageController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(string $stage): RedirectResponse
    {
        return back()->with('status', $this->prompts->toggle($stage));
    }
}
