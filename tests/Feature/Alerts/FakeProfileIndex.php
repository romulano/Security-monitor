<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Services\Alerts\ProfileIndex;
use App\Services\Search\CaseIndex;

/**
 * Percolator de mentira: os testes não tocam no Elasticsearch de verdade.
 * `candidates` nulo simula índice fora do ar (o matching avalia todos).
 */
class FakeProfileIndex extends ProfileIndex
{
    /** @var array<int, string> perfil => query gravada */
    public array $stored = [];

    public ?array $candidates = null;

    public function __construct()
    {
        parent::__construct(app(CaseIndex::class));
    }

    public function put(AlertProfile $profile): bool
    {
        if ($profile->isActive() && $profile->match_query) {
            $this->stored[$profile->id] = $profile->match_query;
        } else {
            unset($this->stored[$profile->id]);
        }

        return true;
    }

    public function forget(AlertProfile $profile): void
    {
        unset($this->stored[$profile->id]);
    }

    public function percolate(NewsCase $case): ?array
    {
        return $this->candidates;
    }

    public function create(): void {}

    public function drop(): void {}
}
