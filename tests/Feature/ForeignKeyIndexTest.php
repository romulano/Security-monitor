<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Toda chave estrangeira precisa de um índice que comece pela coluna dela: sem
 * isso, apagar uma linha do pai varre a tabela filha inteira (medido: 25 s para
 * apagar 10 casos com 300 mil alert_matches). FK nova sem índice derruba o teste
 * até entrar no índice ou na lista abaixo, com o motivo.
 */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    /** FK sem índice de propósito => por quê. */
    private const WITHOUT_INDEX = [
        'alert_profile_techs.tech_id' => 'tabela de ligação pequena; o catálogo quase não perde linha',
        'articles.source_id' => 'medido: contar artigos de uma fonte cai de 107 para 42 ms com 300 mil artigos, em consulta feita 69 vezes por hora; não paga a escrita',
        'crawl_runs.source_id' => 'só o `set null` ao apagar uma fonte (raro); nenhuma consulta filtra por fonte',
        'domain_findings.tech_id' => 'tabela pequena (achados de descoberta)',
        'invites.created_by' => 'poucas dezenas de linhas',
        'invites.used_by' => 'poucas dezenas de linhas',
        'lookalike_watches.user_id' => 'uma linha por Radar (no máximo 5 por usuário)',
        'monitored_domains.user_id' => 'uma linha por domínio de cliente',
        'notification_deliveries.user_id' => 'só ao remover um usuário (raro, e a poda de entregas anda por created_at)',
        'prompt_revisions.user_id' => 'histórico de edição de prompt, poucas linhas',
        'tech_catalog.parent_id' => 'catálogo de algumas centenas de linhas',
    ];

    /** @return list<string> "tabela.coluna" de cada FK sem índice que a comece */
    private function foreignKeysWithoutIndex(): array
    {
        $rows = DB::select(<<<'SQL'
            select c.conrelid::regclass::text as tabela, a.attname as coluna
            from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
            where c.contype = 'f'
              and not exists (
                select 1 from pg_index i
                where i.indrelid = c.conrelid and i.indkey[0] = c.conkey[1] and i.indisvalid
              )
            order by 1, 2
        SQL);

        return array_map(fn ($row) => $row->tabela.'.'.$row->coluna, $rows);
    }

    public function test_every_foreign_key_has_an_index_or_a_reason_not_to(): void
    {
        $expected = array_keys(self::WITHOUT_INDEX);
        sort($expected);

        $this->assertSame(
            $expected,
            $this->foreignKeysWithoutIndex(),
            'A lista de FKs sem índice mudou: FK nova precisa de índice (migration) ou de um motivo em WITHOUT_INDEX; FK que ganhou índice sai da lista.'
        );
    }

    public function test_the_hot_foreign_keys_have_their_indexes(): void
    {
        $indexes = collect(DB::select('select indexname from pg_indexes where schemaname = current_schema()'))->pluck('indexname');

        foreach ([
            'notification_deliveries_match_id_index', 'alert_matches_case_id_index', 'alert_matches_article_id_index',
            'lookalike_captures_lookalike_id_captured_at_index', 'lookalike_domains_last_capture_id_index',
            'lookalike_alerts_lookalike_id_index', 'lookalike_alerts_capture_id_index',
            'crawl_runs_started_at_index', 'notification_deliveries_created_at_id_index',
        ] as $name) {
            $this->assertContains($name, $indexes, $name);
        }
    }

    public function test_the_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_11_04_000100_add_foreign_key_indexes.php');

        DB::transaction(function () use ($migration) {
            $migration->up();
            $migration->up();

            $migration->down();
            $this->assertNotContains('alert_matches_case_id_index', collect(DB::select('select indexname from pg_indexes where schemaname = current_schema()'))->pluck('indexname'));

            $migration->up();
        });

        $this->assertContains('alert_matches_case_id_index', collect(DB::select('select indexname from pg_indexes where schemaname = current_schema()'))->pluck('indexname'));
    }
}
