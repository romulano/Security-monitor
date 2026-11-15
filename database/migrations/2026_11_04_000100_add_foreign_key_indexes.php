<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de chave estrangeira que faltavam. Sem eles, cada linha apagada na
 * tabela pai varre a tabela filha inteira para conferir a FK (a cascata e o
 * `set null` do Postgres), e as buscas por filho viram varredura completa.
 *
 * Medido no Postgres 16 com 300 mil alert_matches, 500 mil entregas, 1 milhão
 * de lookalike_domains e 600 mil capturas (tempos absolutos pessimistas, vale a razão):
 *   - poda de 300 capturas (CaptureIngestor::prune):          70.362 ms → 39 ms
 *   - apagar 100 alert_matches (excluir alerta, perfil, usuário): 19.473 ms → 12 ms
 *   - apagar 10 casos (a fusão noturna, CaseMerger):          25.148 ms → 61 ms
 *   - capturas de um domínio do Radar (CaptureIngestor):       386 ms → 0,94 ms
 *   - UPDATE alert_matches ... WHERE case_id IN (CaseMerger):   36 ms → 3,4 ms
 *   - max(started_at) de crawl_runs (a /sobre):                  6,5 ms → 0,25 ms com 151 mil linhas
 *   - lista de entregas do admin:                                30 ms → 0,63 ms
 *
 * Só acrescenta índice: compatível com o código anterior, que continua no ar
 * enquanto a migration roda (deploy sem queda). Idempotente (IF NOT EXISTS).
 *
 * CREATE INDEX comum, não CONCURRENTLY: as tabelas ainda são pequenas (a produção
 * existe desde 19/10) e cada índice levou, no máximo, 1,4 s de trava de escrita nas
 * medições com 1 milhão de linhas. O CONCURRENTLY esperaria as transações dos
 * workers do supervisor antigo (jobs de até 300 s), estouraria o prazo do deploy
 * (240 s) e deixaria um índice INVALID. O lock_timeout faz a migration falhar
 * rápido, em vez de enfileirar escritas atrás de uma trava; o deploy desfaz e
 * basta repetir. Quando uma dessas tabelas passar de ~1 milhão de linhas, as
 * próximas migrations de índice devem voltar a ser CONCURRENTLY
 * (`$withinTransaction = false`).
 */
return new class extends Migration
{
    /** @var array<string, string> nome do índice => tabela e colunas */
    private const INDEXES = [
        // Apagar uma entrega do alerta, perfil ou caso (FK match_id).
        'notification_deliveries_match_id_index' => 'notification_deliveries (match_id) where match_id is not null',
        // Fusão noturna e exclusão de caso/artigo (FKs case_id e article_id).
        'alert_matches_case_id_index' => 'alert_matches (case_id)',
        'alert_matches_article_id_index' => 'alert_matches (article_id) where article_id is not null',
        // Capturas por domínio do Radar e a poda (FK lookalike_id; a ordem serve à última captura).
        'lookalike_captures_lookalike_id_captured_at_index' => 'lookalike_captures (lookalike_id, captured_at desc)',
        // Apagar uma captura confere quem a tem como última (FK last_capture_id).
        'lookalike_domains_last_capture_id_index' => 'lookalike_domains (last_capture_id) where last_capture_id is not null',
        'lookalike_alerts_lookalike_id_index' => 'lookalike_alerts (lookalike_id)',
        'lookalike_alerts_capture_id_index' => 'lookalike_alerts (capture_id) where capture_id is not null',
        // Telemetria da /sobre e as listas de execuções do admin (ordem por início).
        'crawl_runs_started_at_index' => 'crawl_runs (started_at)',
        // Lista de entregas do admin e a poda por idade.
        'notification_deliveries_created_at_id_index' => 'notification_deliveries (created_at desc, id desc)',
    ];

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        foreach (self::INDEXES as $name => $definition) {
            DB::statement("create index if not exists {$name} on {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("drop index if exists {$name}");
        }
    }
};
