<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListNewsUpdatesRequest;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;

class ListNewsUpdatesController extends Controller
{
    public function __construct(private NewsService $news) {}

    public function __invoke(ListNewsUpdatesRequest $request): JsonResponse
    {
        return response()->json(['updates' => $this->news->updates($request->limit())]);
    }
}
