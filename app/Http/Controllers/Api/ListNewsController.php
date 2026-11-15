<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListNewsRequest;
use App\Services\NewsService;
use Illuminate\Http\JsonResponse;

class ListNewsController extends Controller
{
    public function __construct(private NewsService $news) {}

    /** Com filtros demais, o envelope vem vazio, com o motivo em `error`, e 422. */
    public function __invoke(ListNewsRequest $request): JsonResponse
    {
        return response()->json($this->news->feed($request->term(), $request->page()), $request->tooManyFilters() ? 422 : 200);
    }
}
