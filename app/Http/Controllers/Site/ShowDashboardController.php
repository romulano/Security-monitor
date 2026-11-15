<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ShowDashboardRequest;
use App\Services\NewsService;
use Illuminate\View\View;

class ShowDashboardController extends Controller
{
    public function __construct(private NewsService $news) {}

    public function __invoke(ShowDashboardRequest $request): View
    {
        return view('feed.dashboard', [
            'data' => $this->news->dashboard($request->window()),
            'window' => $request->window(),
            'query' => $request->term(),
        ]);
    }
}
