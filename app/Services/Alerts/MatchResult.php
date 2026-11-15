<?php

namespace App\Services\Alerts;

/** O que o MatchEngine concluiu sobre um caso para um perfil. */
final class MatchResult
{
    /**
     * @param  list<array{type: string, label: string, field: string, weight: int}>  $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly int $score,
        public readonly ?string $suppressed,
    ) {}

    /** Algum termo do perfil apareceu no caso. */
    public function matched(): bool
    {
        foreach ($this->reasons as $reason) {
            if (in_array($reason['type'], ['brand', 'stack', 'mention', 'sector'], true)) {
                return true;
            }
        }

        return false;
    }

    public function isBrand(): bool
    {
        return collect($this->reasons)->contains(fn ($r) => $r['type'] === 'brand');
    }

    /** "Fortinet (FortiGate), Laravel" para listas e prévias. */
    public function summary(int $limit = 3): string
    {
        return collect($this->reasons)
            ->whereIn('type', ['brand', 'stack', 'mention', 'sector'])
            ->pluck('label')
            ->unique()
            ->take($limit)
            ->implode(', ');
    }
}
