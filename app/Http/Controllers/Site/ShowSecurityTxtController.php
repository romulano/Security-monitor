<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\SecurityTxt;
use Illuminate\Http\Response;

/** /.well-known/security.txt (RFC 9116), em texto puro e UTF-8, como a RFC pede. */
class ShowSecurityTxtController extends Controller
{
    public function __construct(private SecurityTxt $securityTxt) {}

    public function __invoke(): Response
    {
        return response($this->securityTxt->render(now()), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
