<?php

namespace App\Http\Requests\Alerts\Lookalikes;

use App\Http\Requests\Request;
use App\Models\LookalikeWatch;
use App\Services\Company\Visibility;
use App\Services\Lookalike\RadarInterview;

/**
 * A entrevista do Radar: `?radar=` diz qual Radar ela edita, e só quem configura esse Radar entra (R14; 404 para o
 * resto, inclusive o membro que só vê). Sem `?radar=` é um Radar novo, que nasce no passo 1: só quem pode criar.
 */
abstract class RadarInterviewRequest extends Request
{
    private ?LookalikeWatch $watch = null;

    private bool $loaded = false;

    public function authorize(): bool
    {
        return $this->filled('radar') ? $this->watch() !== null : $this->mayCreate();
    }

    /** O Radar de `?radar=`, só se a conta o configura. */
    public function watch(): ?LookalikeWatch
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $found = $this->filled('radar')
                ? LookalikeWatch::query()->visibleTo($this->user())->with('domain')->find($this->integer('radar'))
                : null;
            $this->watch = $found && app(Visibility::class)->manages($this->user(), $found) ? $found : null;
        }

        return $this->watch;
    }

    /** A conta cria Radares (a pessoal; na empresa, a admin e quem responde por algum setor). */
    public function mayCreate(): bool
    {
        return app(RadarInterview::class)->mayCreate($this->user());
    }

    public function step(): int
    {
        return (int) $this->route('passo');
    }
}
