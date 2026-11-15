<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Request;
use App\Models\GithubMonitor;
use App\Services\Github\GithubInterview;

/** A entrevista do GitHub: o monitoramento da conta, que nasce vazio na primeira vez. */
abstract class GithubInterviewRequest extends Request
{
    private ?GithubMonitor $monitor = null;

    private bool $loaded = false;

    public function authorize(): bool
    {
        return ! $this->filled('monitor');
    }

    public function rules(): array
    {
        return [];
    }

    /** O monitoramento da entrevista: o da conta, que nasce vazio na primeira vez. */
    public function monitor(): ?GithubMonitor
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $this->monitor = app(GithubInterview::class)->monitorFor($this->user());
        }

        return $this->monitor;
    }

    public function step(): int
    {
        return (int) $this->route('passo');
    }
}
