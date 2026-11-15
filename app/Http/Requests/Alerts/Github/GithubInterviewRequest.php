<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Request;
use App\Models\GithubMonitor;
use App\Services\Company\Viewer;
use App\Services\Access\Visibility;
use App\Services\Github\GithubInterview;

/**
 * A entrevista do GitHub. Conta pessoal: o monitoramento dela (nasce vazio na primeira vez, como sempre). Empresa:
 * `?monitor=` diz qual, e só quem o configura entra (R14; 404 para o resto, inclusive o membro); sem `?monitor=` é um
 * monitoramento novo, que nasce no passo 1 com o setor, e só para quem pode criar.
 */
abstract class GithubInterviewRequest extends Request
{
    private ?GithubMonitor $monitor = null;

    private bool $loaded = false;

    public function authorize(): bool
    {
        if ($this->personal()) {
            return ! $this->filled('monitor');
        }

        return $this->filled('monitor') ? $this->monitor() !== null : app(GithubInterview::class)->mayStart($this->user());
    }

    public function rules(): array
    {
        return [];
    }

    /** O monitoramento da entrevista; nulo quando a empresa começa um novo (ou quando a conta não o configura). */
    public function monitor(): ?GithubMonitor
    {
        if (! $this->loaded) {
            $this->loaded = true;

            if ($this->personal()) {
                $this->monitor = app(GithubInterview::class)->monitorFor($this->user());
            } elseif ($this->filled('monitor')) {
                $found = GithubMonitor::query()->visibleTo($this->user())->find($this->integer('monitor'));
                $this->monitor = $found && app(Visibility::class)->manages($this->user(), $found) ? $found : null;
            }
        }

        return $this->monitor;
    }

    public function step(): int
    {
        return (int) $this->route('passo');
    }

    private function personal(): bool
    {
        return app(Visibility::class)->viewer($this->user())->kind === Viewer::PERSONAL;
    }
}
