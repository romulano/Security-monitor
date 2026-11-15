<?php

namespace App\Http\Requests\Site;

use App\Http\Requests\Request;

/**
 * O sitemap não tem campo: a parte vem da rota (só número, whereNumber), e o
 * If-None-Match / If-Modified-Since do robô decidem o 304.
 */
class ShowSitemapRequest extends Request
{
    public function rules(): array
    {
        return [];
    }
}
