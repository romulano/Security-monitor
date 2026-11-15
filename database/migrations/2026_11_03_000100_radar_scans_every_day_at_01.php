<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A varredura completa do Radar passou de semanal para diária, à 01:00 de
 * Brasília. Quem já estava no Radar tinha a próxima marcada para até 7 dias
 * adiante: puxa para a próxima 01:00, para a regra nova valer já nesta noite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $next = now('America/Sao_Paulo')->setTime(1, 0);

        if ($next->isPast()) {
            $next->addDay();
        }

        $next->setTimezone(config('app.timezone'));

        DB::table('lookalike_watches')
            ->where('status', 'active')
            ->where('next_scan_at', '>', $next)
            ->update(['next_scan_at' => $next]);
    }

    public function down(): void
    {
        // A data antiga não se recupera e não faz falta: a próxima varredura completa recalcula.
    }
};
