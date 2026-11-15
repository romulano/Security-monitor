<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ShowCaseRequest;
use App\Services\Admin\ReportReviewReport;
use App\Services\NewsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShowCaseController extends Controller
{
    public function __construct(private NewsService $news, private ReportReviewReport $reviews) {}

    public function __invoke(ShowCaseRequest $request, string $uuid): View|RedirectResponse
    {
        $lang = $request->language();
        $case = $this->news->case($uuid, $lang, withExtraction: true, withHidden: $request->seesHidden());

        // Matéria apagada pela fusão de matérias: o link antigo (de um alerta, de
        // um favorito) leva para a matéria que juntou o conteúdo dela.
        if (! $case && $into = $this->news->mergedInto($uuid)) {
            return redirect()->route('feed.show', $into, 301);
        }

        // Escondida do feed público (fora de segurança): 410, que diz ao
        // buscador que ela saiu de propósito. O admin continua vendo.
        abort_if(! $case && $this->news->hidden($uuid), 410);
        abort_if(! $case, 404);

        // Só o admin vê o que o revisor do relatório disse (e a revisão pendente).
        return view('feed.show', ['case' => $case, 'lang' => $lang, 'review' => $request->seesHidden() ? $this->reviews->forCase($uuid) : null]);
    }
}
