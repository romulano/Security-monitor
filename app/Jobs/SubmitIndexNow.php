<?php

namespace App\Jobs;

use App\Services\IndexNow\IndexNow;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda a fila do IndexNow num aviso só (IndexNow::flush). Um por vez na fila:
 * as matérias de uma rodada da coleta, que chegam juntas, saem no mesmo aviso.
 * Sem resposta, os endereços voltam para a fila e o job tenta de novo; nada
 * espera por ele, e o site não depende do aviso.
 */
class SubmitIndexNow implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct()
    {
        // Depois dos alertas, no mesmo worker: o aviso aos buscadores nunca passa na frente de um alerta.
        $this->onQueue('notifications-low');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(IndexNow $indexNow): void
    {
        $indexNow->flush();
    }
}
