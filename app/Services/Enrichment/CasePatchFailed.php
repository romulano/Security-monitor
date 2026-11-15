<?php

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * A análise devolveu o relatório da matéria por trechos (`case_patch`), mas o
 * trecho não encaixa no relatório atual: quem chama refaz a análise no
 * formato de relatório inteiro (EnrichmentPrompt::withPatch).
 */
class CasePatchFailed extends RuntimeException {}
