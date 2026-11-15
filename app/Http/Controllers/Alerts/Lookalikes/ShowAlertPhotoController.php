<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Models\LookalikeAlert;
use App\Services\Lookalike\LookalikeShots;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A foto do aviso, por link assinado: o Slack, o Discord e o Telegram buscam sem login. */
class ShowAlertPhotoController extends Controller
{
    public function __construct(private LookalikeShots $shots) {}

    public function __invoke(LookalikeAlert $alert): BinaryFileResponse
    {
        $file = $this->shots->ofAlert($alert);
        abort_unless($file, 404);

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'public, max-age=2592000', 'X-Robots-Tag' => 'noindex']);
    }
}
