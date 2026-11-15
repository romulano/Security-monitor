<?php

namespace Tests\Feature;

use App\Services\Alerts\AlertReviewer;
use App\Services\Enrichment\ErrorExplainer;
use App\Services\Exploits\ExploitExplainer;
use App\Services\Github\ExtraInterpreter;
use App\Services\Github\GithubJudge;
use App\Services\Github\GithubTriage;
use App\Services\Lookalike\LookalikeReviewer;
use App\Services\Merge\MergeEditor;
use App\Services\Review\ReportReviewer;
use App\Services\Vulnerability\VulnerabilityExplainer;
use App\Support\TagCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O prompt de sistema das chamadas com texto de terceiros é igual em toda
 * chamada: as etiquetas vão com o marcador CODE e o código sorteado vai na
 * primeira linha da mensagem. É o que deixa o hermes ler o sistema do cache.
 */
class TagCodeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{callable(): string}> */
    public static function systems(): array
    {
        return [
            'juiz dos alertas' => [fn () => AlertReviewer::system('English')],
            'radar' => [fn () => LookalikeReviewer::system()],
            'github adicional' => [fn () => ExtraInterpreter::system()],
            'github triagem' => [fn () => GithubTriage::system()],
            'github parecer' => [fn () => GithubJudge::system()],
            'fusão confirmar' => [fn () => MergeEditor::confirmSystem()],
            'fusão escrever' => [fn () => MergeEditor::writeSystem()],
            'fusão enriquecer' => [fn () => MergeEditor::enrichSystem()],
            'vulnerabilidade' => [fn () => VulnerabilityExplainer::system()],
            'exploit' => [fn () => ExploitExplainer::system()],
            'erro' => [fn () => ErrorExplainer::system()],
            'revisor' => [fn () => ReportReviewer::system()],
        ];
    }

    #[DataProvider('systems')]
    public function test_the_system_prompt_is_the_same_in_every_call_and_cites_the_tags_with_the_marker(callable $system): void
    {
        $text = $system();

        $this->assertSame($text, $system());
        $this->assertMatchesRegularExpression('/<[a-z-]+-CODE>/', $text);
        // Fora o exemplo da própria regra, nenhum código no sistema.
        $this->assertDoesNotMatchRegularExpression('/<[a-z-]+-[0-9a-f]{8}>/', str_replace(TagCode::rule(), '', $text), 'nenhum código sorteado no sistema');
        $this->assertStringEndsWith(TagCode::rule(), $text);
    }

    public function test_the_message_opens_with_the_code_of_the_call(): void
    {
        $this->assertSame("Code: 1a2b3c4d\n\n", TagCode::header('1a2b3c4d'));
        $this->assertStringContainsString('"Code: …"', TagCode::rule());
    }
}
