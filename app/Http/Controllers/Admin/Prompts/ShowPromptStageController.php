<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Services\Admin\PromptManager;
use Illuminate\View\View;

class ShowPromptStageController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(string $stage): View
    {
        return view('admin.prompts.show', ['stage' => $this->prompts->stage($stage)]);
    }
}
