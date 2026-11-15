<?php

namespace App\Http\Controllers\Admin\Prompts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Prompts\ListPromptsRequest;
use App\Services\Admin\PromptManager;
use App\Services\Prompts\PromptCatalog;
use Illuminate\View\View;

class ListPromptsController extends Controller
{
    public function __construct(private PromptManager $prompts) {}

    public function __invoke(ListPromptsRequest $request): View
    {
        return view('admin.prompts.index', [
            'groups' => $this->prompts->overview($request->group()),
            'filters' => PromptCatalog::GROUPS,
            'current' => $request->group(),
        ]);
    }
}
