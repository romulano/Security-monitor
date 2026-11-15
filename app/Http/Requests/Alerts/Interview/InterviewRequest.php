<?php

namespace App\Http\Requests\Alerts\Interview;

use App\Http\Requests\Request;
use App\Services\Alerts\AlertPanel;

/** Telas da entrevista: `?alerta=ID` diz qual alerta do cliente está sendo editado; sem ele, o primeiro. */
abstract class InterviewRequest extends Request
{
    /** O `?alerta=` (o service garante que é do cliente) ou nulo para o primeiro. */
    public function alertId(): ?int
    {
        return $this->integer('alerta') ?: null;
    }

    /**
     * Alerta novo de empresa (DESENHO-5.2 §2.1, como o GitHub): sem `?alerta=`, o passo 1 é o "Novo alerta" e o
     * rascunho só nasce ao gravá-lo, já com o setor. A conta pessoal segue com o rascunho de sempre.
     */
    public function startsNew(): bool
    {
        return false;
    }

    /** Só no passo 1 e só para quem pode criar (a admin e o responsável; o membro, não). */
    protected function mayStartNew(): bool
    {
        return $this->step() === 1 && app(AlertPanel::class)->canCreate($this->user());
    }

    /** O `{passo}` da URL. */
    protected function step(): int
    {
        return (int) $this->route('passo');
    }
}
