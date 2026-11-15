<?php

namespace App\Http\Controllers\Admin\Articles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Articles\UpdateArticleTextRequest;
use App\Models\Article;
use App\Services\Crawler\ManualTextIntake;
use Illuminate\Http\RedirectResponse;

class UpdateArticleTextController extends Controller
{
    public function __construct(private ManualTextIntake $texts) {}

    public function __invoke(UpdateArticleTextRequest $request, Article $article): RedirectResponse
    {
        $result = $this->texts->submit($article, $request->pasted(), $request->pastedFormat(), $request->prints());

        if (! $result['queued']) {
            return redirect()->route('admin.collections', ['extraction' => 'manual'])->with('status', $result['message']);
        }

        return redirect()->route('admin.articles.text', $article)->with('status', sprintf(
            'Recebido. A IA está lendo %d print(s) e %d imagem(ns) do HTML; esta página mostra o resultado quando terminar.',
            $result['prints'],
            $result['images'],
        ));
    }
}
