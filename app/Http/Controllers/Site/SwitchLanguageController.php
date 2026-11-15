<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\ReportLanguage;
use Illuminate\Http\RedirectResponse;

/** Seletor EN | PT-BR do cabeçalho: guarda a escolha e volta para onde estava. */
class SwitchLanguageController extends Controller
{
    public function __invoke(string $lang): RedirectResponse
    {
        return redirect()->back(fallback: route('feed.index'))
            ->withCookie(cookie()->forever(ReportLanguage::COOKIE, $lang));
    }
}
