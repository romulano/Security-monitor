<?php

namespace App\Http\Controllers\Alerts\Lookalikes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\Lookalikes\ShowReferenceShotRequest;
use App\Models\LookalikeWatch;
use App\Services\Lookalike\LookalikeShots;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Print de referência do site do cliente. */
class ShowReferenceShotController extends Controller
{
    public function __construct(private LookalikeShots $shots) {}

    public function __invoke(ShowReferenceShotRequest $request, LookalikeWatch $watch, int $pagina, string $aparelho): BinaryFileResponse
    {
        $file = $this->shots->ofReference($watch, $pagina, $aparelho);
        abort_unless($file, 404);

        $response = response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800']);

        if ($request->download()) {
            $response->setContentDisposition('attachment', "referencia-{$watch->domain->domain}-{$pagina}-{$aparelho}.jpg");
        }

        return $response;
    }
}
