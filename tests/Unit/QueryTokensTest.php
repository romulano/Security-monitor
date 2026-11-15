<?php

namespace Tests\Unit;

use App\Support\QueryTokens;
use PHPUnit\Framework\TestCase;

class QueryTokensTest extends TestCase
{
    public function test_conta_os_filtros_campo_valor_e_nao_o_texto_livre(): void
    {
        $this->assertSame(0, QueryTokens::filterCount('ransomware healthcare'));
        $this->assertSame(1, QueryTokens::filterCount('cves:CVE-2026-*'));
        $this->assertSame(2, QueryTokens::filterCount('criticity:critical threat_actors:"Scattered Spider"'));
        // O robô da busca de 01/11/2026: 5 facetas aleatórias.
        $this->assertSame(5, QueryTokens::filterCount('ttps_attack:T1190 criticity:critical victim_sectors:cryptocurrency victim_sectors:retail countries_code:SG'));
        // Entre parênteses, depois de NOT ou de - também contam; o http:// de um endereço e uma hora, não.
        $this->assertSame(3, QueryTokens::filterCount('(cves:CVE-1 OR cves:CVE-2) NOT -criticity:low'));
        $this->assertSame(1, QueryTokens::filterCount('"http://evil.example" 12:30 title.raw:Acme'));
    }

    public function test_quebra_respeitando_aspas(): void
    {
        $this->assertSame(
            ['ransomware', 'threat_actors:"Scattered Spider"', 'AND', 'criticity:critical'],
            QueryTokens::split('ransomware threat_actors:"Scattered Spider" AND criticity:critical')
        );
    }

    public function test_coloca_aspas_apenas_quando_o_valor_precisa(): void
    {
        $this->assertSame('cves:CVE-2026-16812', QueryTokens::token('cves', 'CVE-2026-16812'));
        $this->assertSame('threat_actors:"Scattered Spider"', QueryTokens::token('threat_actors', 'Scattered Spider'));
    }

    public function test_toggle_adiciona_e_remove_preservando_o_texto_livre(): void
    {
        $query = QueryTokens::toggle('ransomware', 'victim_sectors', 'healthcare');
        $this->assertSame('ransomware victim_sectors:healthcare', $query);

        $this->assertSame('ransomware', QueryTokens::toggle($query, 'victim_sectors', 'healthcare'));
    }

    public function test_criticidade_e_exclusiva(): void
    {
        $query = QueryTokens::setCriticity('ransomware criticity:low', 'critical');

        $this->assertSame('ransomware criticity:critical', $query);
        $this->assertSame('critical', QueryTokens::criticity($query));
    }

    public function test_clicar_de_novo_na_mesma_criticidade_limpa_o_filtro(): void
    {
        $this->assertSame('ransomware', QueryTokens::setCriticity('ransomware criticity:critical', 'critical'));
    }

    public function test_operadores_nao_viram_chip_removivel(): void
    {
        $chips = QueryTokens::chips('criticity:critical AND victim_sectors:healthcare');

        $this->assertTrue($chips[0]['is_field']);
        $this->assertTrue($chips[1]['is_operator']);
        $this->assertFalse($chips[2]['is_operator']);
    }

    public function test_has_ignora_diferenca_de_caixa(): void
    {
        $this->assertTrue(QueryTokens::has('threat_actors:"scattered spider"', 'threat_actors', 'Scattered Spider'));
    }
}
