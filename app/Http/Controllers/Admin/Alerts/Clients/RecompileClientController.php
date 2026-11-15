<?php

namespace App\Http\Controllers\Admin\Alerts\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Alerts\Clients\RecompileClientRequest;
use App\Models\User;
use App\Services\Alerts\InterviewService;
use Illuminate\Http\RedirectResponse;

class RecompileClientController extends Controller
{
    public function __construct(private InterviewService $interview) {}

    public function __invoke(RecompileClientRequest $request, User $user): RedirectResponse
    {
        $this->interview->rebuild($request->profile());

        return back()->with('status', 'Perfil recompilado.');
    }
}
