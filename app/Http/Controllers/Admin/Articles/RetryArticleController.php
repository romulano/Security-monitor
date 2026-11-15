<?php

namespace App\Http\Controllers\Admin\Articles;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Admin\ArticleManager;
use Illuminate\Http\RedirectResponse;

class RetryArticleController extends Controller
{
    public function __construct(private ArticleManager $articles) {}

    public function __invoke(Article $article): RedirectResponse
    {
        $this->articles->retry($article);

        return back()->with('status', 'Artigo reenfileirado para análise.');
    }
}
