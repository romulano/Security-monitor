<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;
use App\Models\LookalikeWatch;
use App\Services\Company\Visibility;
use Illuminate\Support\Collection;

/**
 * A aba do Radar e os botões dela ("Verificar agora", pausar, excluir, trocar o setor): `?radar=` diz qual Radar,
 * entre os que a conta vê (404 se não vê); sem ele, o primeiro ativo, ou o primeiro. Os botões configuram: pedem
 * `manages` e, no Radar de empresa, o `?radar=` explícito (o "primeiro" de cada conta é outro, DESENHO-5.2 §4.3).
 */
abstract class RadarPageRequest extends Request
{
    /** @var Collection<int, LookalikeWatch>|null */
    private ?Collection $watches = null;

    public function authorize(): bool
    {
        return ! $this->filled('radar') || $this->watch() !== null;
    }

    /** Os Radares que a conta vê, com o domínio e o setor, do mais antigo ao mais novo. @return Collection<int, LookalikeWatch> */
    public function watches(): Collection
    {
        return $this->watches ??= LookalikeWatch::query()->visibleTo($this->user())->with(['domain', 'sector'])->oldest('id')->get();
    }

    /** O Radar de `?radar=` (nulo se a conta não o vê) ou o primeiro ativo, ou o primeiro. */
    public function watch(): ?LookalikeWatch
    {
        $watches = $this->watches();

        return $this->filled('radar')
            ? $watches->firstWhere('id', $this->integer('radar'))
            : ($watches->first(fn (LookalikeWatch $watch) => $watch->isActive()) ?? $watches->first());
    }

    /** Os botões que configuram: a conta configura este Radar, e o de empresa veio pelo `?radar=`. */
    protected function managesWatch(): bool
    {
        $watch = $this->watch();

        return $watch !== null
            && ($watch->company_id === null || $this->filled('radar'))
            && app(Visibility::class)->manages($this->user(), $watch);
    }
}
