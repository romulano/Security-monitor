<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ShowFeedRequest;
use App\Services\NewsService;
use Illuminate\Http\Response;

class ShowFeedController extends Controller
{
    public function __construct(private NewsService $news) {}

    /** Com filtros demais, a página diz quais tirar, sem consultar a busca, e responde 422. */
    public function __invoke(ShowFeedRequest $request): Response
    {
        return response()->view(
            'feed.index',
            $this->news->feedPage($request->term(), $request->page(), $request->language()),
            $request->tooManyFilters() ? 422 : 200,
        );
    }
}
