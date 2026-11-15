<?php

namespace App\Services\Admin;

use App\Jobs\ExpandCustomAnswer;
use App\Models\AlertProfile;
use App\Models\CustomAnswerTerm;
use App\Services\Alerts\InterviewService;

/**
 * Admin → Alertas → Respostas escritas: o que os clientes digitaram em
 * "Outro" na entrevista e os termos de busca que cada resposta virou. Corrigir
 * aqui vale para todos os clientes que escreveram a mesma coisa.
 */
class CustomAnswerManager
{
    public function __construct(private InterviewService $interview) {}

    public function page(): array
    {
        $profiles = AlertProfile::query()->whereNotNull('custom_answers')->with('user:id,name,email')->get();
        $usage = [];

        foreach ($profiles as $profile) {
            foreach ((array) $profile->custom_answers as $key => $labels) {
                foreach ((array) $labels as $label) {
                    $usage[CustomAnswerTerm::kindFor($key).'|'.CustomAnswerTerm::normalize((string) $label)][$profile->id] = $profile->company_name ?: $profile->user?->name;
                }
            }
        }

        return [
            'answers' => CustomAnswerTerm::query()->orderByRaw("status = 'ready'")->latest()->get(),
            'usage' => $usage,
        ];
    }

    /**
     * Grava a correção do admin (a IA não sobrescreve depois) e recompila os
     * perfis que usam a resposta. `$values`: termos separados por vírgula; em
     * região, códigos de país de 2 letras.
     */
    public function update(CustomAnswerTerm $answer, string $values): void
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $values)))));

        $answer->forceFill($answer->kind === 'region'
            ? ['codes' => array_values(array_filter(array_map('strtoupper', $items), fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1))]
            : ['terms' => array_map('mb_strtolower', $items)]
        )->forceFill(['status' => 'ready', 'error' => null, 'edited_by_admin' => true])->save();

        foreach (ExpandCustomAnswer::profilesUsing($answer) as $profile) {
            $this->interview->recompile($profile);
        }
    }

    /** Devolve a resposta para a IA gerar os termos de novo. */
    public function regenerate(CustomAnswerTerm $answer): void
    {
        $answer->forceFill(['status' => 'pending', 'error' => null, 'edited_by_admin' => false])->save();
        ExpandCustomAnswer::dispatch($answer->id);
    }
}
