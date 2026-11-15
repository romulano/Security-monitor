<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowCaptureShotRequest;
use App\Models\LookalikeCapture;
use App\Services\Lookalike\LookalikeShots;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Print de uma visita (aparelho e momento). Só o dono vê; `?baixar=1` vem como anexo, para a denúncia. */
class ShowCaptureShotController extends Controller
{
    public function __construct(private LookalikeShots $shots) {}

    public function __invoke(ShowCaptureShotRequest $request, LookalikeCapture $capture, string $aparelho, string $momento = 'first'): BinaryFileResponse
    {
        $file = $this->shots->ofCapture($capture, $aparelho, $momento === 'depois' ? 'second' : 'first');
        abort_unless($file, 404);

        $response = response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800']);

        if ($request->download()) {
            $response->setContentDisposition('attachment', "{$capture->lookalike->host}-{$capture->captured_at?->format('Y-m-d')}-{$aparelho}".($momento === 'depois' ? '-10s' : '').'.jpg');
        }

        return $response;
    }
}
