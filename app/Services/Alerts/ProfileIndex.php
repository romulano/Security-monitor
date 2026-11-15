<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Services\Search\CaseIndex;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Índice percolator: cada perfil ativo é uma query guardada, e cada caso novo
 * é "percolado" para achar os perfis que ele pode interessar. Usa o mesmo
 * mapping dos casos, senão o `all` e os analisadores não batem.
 *
 * É um pré-filtro: se o Elasticsearch falhar, percolate() devolve null e o
 * matching avalia todos os perfis ativos.
 */
class ProfileIndex
{
    public function __construct(private CaseIndex $cases) {}

    public function name(): string
    {
        return (string) config('secmon.alerts.profiles_index');
    }

    public function exists(): bool
    {
        try {
            return $this->cases->client()->indices()->exists(['index' => $this->name()])->asBool();
        } catch (Throwable) {
            return false;
        }
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        $this->cases->client()->indices()->create([
            'index' => $this->name(),
            'body' => [
                'settings' => $this->cases->settings(),
                'mappings' => ['properties' => $this->cases->properties() + [
                    'query' => ['type' => 'percolator'],
                    'profile_id' => ['type' => 'long'],
                    'query_version' => ['type' => 'integer'],
                ]],
            ],
        ]);
    }

    public function drop(): void
    {
        if ($this->exists()) {
            $this->cases->client()->indices()->delete(['index' => $this->name()]);
        }
    }

    /** Grava (ou remove, se o perfil não deve receber nada) a query do perfil. */
    public function put(AlertProfile $profile): bool
    {
        try {
            if (! $profile->isActive() || ! $profile->match_query) {
                $this->forget($profile);

                return true;
            }

            $this->create();
            $this->cases->client()->index([
                'index' => $this->name(),
                'id' => (string) $profile->id,
                'refresh' => 'wait_for',
                'body' => [
                    'profile_id' => $profile->id,
                    'query_version' => $profile->query_version,
                    'query' => self::queryFor($profile->match_query),
                ],
            ]);

            $profile->forceFill(['percolated_at' => now()])->saveQuietly();

            return true;
        } catch (Throwable $e) {
            Log::warning('Percolator: perfil não gravado', ['profile_id' => $profile->id, 'erro' => $e->getMessage()]);

            return false;
        }
    }

    public function forget(AlertProfile $profile): void
    {
        try {
            $this->cases->client()->delete(['index' => $this->name(), 'id' => (string) $profile->id]);
        } catch (ClientResponseException) {
            // não existia
        } catch (Throwable $e) {
            Log::warning('Percolator: perfil não removido', ['profile_id' => $profile->id, 'erro' => $e->getMessage()]);
        }

        if ($profile->percolated_at) {
            $profile->forceFill(['percolated_at' => null])->saveQuietly();
        }
    }

    /** @return list<int>|null ids dos perfis candidatos; null = índice indisponível */
    public function percolate(NewsCase $case): ?array
    {
        try {
            $res = $this->cases->client()->search([
                'index' => $this->name(),
                'body' => [
                    'size' => 1000,
                    '_source' => ['profile_id'],
                    'query' => ['percolate' => ['field' => 'query', 'document' => $this->cases->documentFor($case)]],
                ],
            ])->asArray();
        } catch (Throwable $e) {
            Log::warning('Percolator indisponível, avaliando todos os perfis', ['case_id' => $case->id, 'erro' => $e->getMessage()]);

            return null;
        }

        return array_values(array_map(fn ($hit) => (int) Arr::get($hit, '_source.profile_id'), Arr::get($res, 'hits.hits', [])));
    }

    public static function queryFor(string $queryString): array
    {
        return [
            'query_string' => [
                'query' => $queryString,
                'default_field' => 'all',
                'default_operator' => 'OR',
                'analyze_wildcard' => true,
                'allow_leading_wildcard' => true,
                'lenient' => true,
            ],
        ];
    }
}
