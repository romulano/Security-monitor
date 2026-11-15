<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ShowNewsCaseController extends Controller
{
    public function __construct(private NewsService $news) {}

    public function __invoke(string $uuid): JsonResponse|RedirectResponse
    {
        $case = $this->news->case($uuid);

        // Matéria apagada pela fusão de matérias: o formato não muda, só o endereço.
        if (! $case && $into = $this->news->mergedInto($uuid)) {
            return redirect()->action(self::class, $into, 301);
        }

        if (! $case) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($case);
    }
}
