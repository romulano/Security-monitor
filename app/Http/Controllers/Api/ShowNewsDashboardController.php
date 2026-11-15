<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ShowNewsDashboardRequest;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;

class ShowNewsDashboardController extends Controller
{
    public function __construct(private NewsService $news) {}

    public function __invoke(ShowNewsDashboardRequest $request): JsonResponse
    {
        return response()->json($this->news->dashboard($request->window()));
    }
}
