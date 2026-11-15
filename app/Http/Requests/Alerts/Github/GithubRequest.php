<?php

namespace App\Http\Requests\Alerts\Github;

use App\Http\Requests\Request;
use App\Models\GithubMonitor;
use App\Services\Access\Visibility;
use Illuminate\Support\Collection;

/**
 * A página do GitHub e os botões dela ("Verificar agora", pausar, excluir, concluir a entrevista, trocar o setor):
 * `?monitor=` diz qual monitoramento, entre os que a conta vê (404 se não vê); sem ele, o primeiro. Os botões
 * configuram: pedem `manages` e, no monitoramento de empresa, o `?monitor=` explícito (DESENHO-5.2 §4.3).
 */
abstract class GithubRequest extends Request
{
    /** @var Collection<int, GithubMonitor>|null */
    private ?Collection $monitors = null;

    public function authorize(): bool
    {
        return ! $this->filled('monitor') || $this->monitor() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    /** Os monitoramentos que a conta vê, do mais antigo ao mais novo. @return Collection<int, GithubMonitor> */
    public function monitors(): Collection
    {
        return $this->monitors ??= GithubMonitor::query()->visibleTo($this->user())->oldest('id')->get();
    }

    /** O monitoramento de `?monitor=` (nulo se a conta não o vê) ou o primeiro. */
    public function monitor(): ?GithubMonitor
    {
        return $this->filled('monitor') ? $this->monitors()->firstWhere('id', $this->integer('monitor')) : $this->monitors()->first();
    }

    /** Os botões que configuram: a conta configura este monitoramento, e o de empresa veio pelo `?monitor=`. */
    protected function managesMonitor(): bool
    {
        $monitor = $this->monitor();

        return $monitor !== null
            && app(Visibility::class)->manages($this->user(), $monitor);
    }
}
