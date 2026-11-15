<?php

namespace Tests\Unit;

use App\Services\Alerts\Slack\WorkflowVariables;
use PHPUnit\Framework\TestCase;

/** A notificação do fluxo de trabalho do Slack: uma variável de texto só, `notificacao`. */
class WorkflowVariablesTest extends TestCase
{
    private function payload(array $body = [], array $buttons = []): array
    {
        return ['text' => 'fallback', 'blocks' => array_merge([
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'CRITICAL · Falha no Nginx']],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Por que você recebeu: *Nginx* · <https://example.com/x|ver>']],
            ['type' => 'section', 'fields' => [['type' => 'mrkdwn', 'text' => "*Fontes*\n4"]]],
        ], $body, [['type' => 'actions', 'elements' => $buttons ?: [
            ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Ler no Security Monitor'], 'url' => 'https://example.com/materia/1'],
            ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Relevante'], 'url' => 'https://example.com/a/1/relevante?signature=abc'],
            ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Não é relevante'], 'url' => 'https://example.com/a/1/nao-relevante?signature=def'],
        ]]])];
    }

    public function test_the_whole_notification_goes_in_one_variable(): void
    {
        $vars = WorkflowVariables::from($this->payload());

        $this->assertSame(['notificacao'], array_keys($vars));
        $this->assertSame('notificacao', WorkflowVariables::KEY);
        $this->assertSame(
            "CRITICAL · Falha no Nginx\n\nPor que você recebeu: Nginx · ver (https://example.com/x)\n\nFontes: 4\n\n"
            ."Ler no Security Monitor: https://example.com/materia/1\n\nRelevante: https://example.com/a/1/relevante?signature=abc\n\nNão é relevante: https://example.com/a/1/nao-relevante?signature=def",
            $vars['notificacao'],
        );
    }

    public function test_the_site_print_goes_as_a_link_and_code_loses_its_fences(): void
    {
        $text = WorkflowVariables::from($this->payload([
            ['type' => 'image', 'image_url' => 'https://example.com/r/foto/1?signature=zzz', 'alt_text' => 'Print'],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '```ACME_KEY=Zq8x••••```']],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Teste :white_check_mark:']]],
            ['type' => 'divider'],
        ]))['notificacao'];

        $this->assertStringContainsString("Print do site: https://example.com/r/foto/1?signature=zzz\n\nACME_KEY=Zq8x••••\n\nTeste ✅", $text);
        $this->assertStringNotContainsString('```', $text);
        $this->assertStringNotContainsString("\n\n\n", $text, 'divisor não deixa linhas em branco sobrando');
    }

    public function test_a_long_body_is_cut_but_the_links_to_act_stay(): void
    {
        $long = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => str_repeat('palavra ', 800)]];
        $text = WorkflowVariables::from($this->payload([$long]))['notificacao'];

        $this->assertLessThanOrEqual(3600, mb_strlen($text));
        $this->assertStringContainsString('...', $text);
        $this->assertStringEndsWith('nao-relevante?signature=def', $text);
        $this->assertStringContainsString('Relevante: https://example.com/a/1/relevante?signature=abc', $text);
    }

    public function test_it_falls_back_to_the_plain_text_when_there_are_no_blocks(): void
    {
        $this->assertSame(['notificacao' => 'Só o texto'], WorkflowVariables::from(['text' => 'Só o texto']));
    }
}
