<?php

namespace App\Http\Controllers\Admin\Articles;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Crawler\ManualTextIntake;
use Illuminate\View\View;

class EditArticleTextController extends Controller
{
    public function __construct(private ManualTextIntake $texts) {}

    public function __invoke(Article $article): View
    {
        return view('admin.article-text', ['article' => $this->texts->forEdit($article)]);
    }
}
