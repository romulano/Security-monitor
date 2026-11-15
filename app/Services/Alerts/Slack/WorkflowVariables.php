<?php

namespace App\Services\Alerts\Slack;

use Illuminate\Support\Str;

/**
 * Webhook de fluxo de trabalho do Slack (Workflow Builder, "Começa com um
 * webhook") não aceita Block Kit nem imagem: só variáveis de texto no primeiro
 * nível, declaradas no gatilho. A notificação inteira (texto, links, o de
 * "relevante" e "não é relevante" e o do print do site) vai numa variável só,
 * `notificacao`; quem monta o fluxo só precisa inserir ela.
 */
class WorkflowVariables
{
    /** A chave que o cliente declara no gatilho: minúscula, sem acento nem caractere especial. */
    public const KEY = 'notificacao';

    /** Variáveis de texto passam bem desse tamanho; a mensagem do Slack para em 4.000. */
    private const MAX = 3500;

    /** @return array{notificacao: string} */
    public static function from(array $payload): array
    {
        $lines = [];
        $links = null;

        foreach ($payload['blocks'] ?? [] as $block) {
            switch ($block['type'] ?? null) {
                case 'header':
                    $lines[] = trim((string) ($block['text']['text'] ?? ''));
                    break;

                case 'section':
                    if (isset($block['text']['text'])) {
                        $lines[] = self::plain($block['text']['text']);
                    }

                    foreach ($block['fields'] ?? [] as $field) {
                        // "*Fontes*\n3" → "Fontes: 3"
                        $lines[] = preg_replace('/\n+/', ': ', self::plain((string) ($field['text'] ?? '')), 1);
                    }
                    break;

                case 'context':
                    foreach ($block['elements'] ?? [] as $element) {
                        $lines[] = self::plain((string) ($element['text'] ?? ''));
                    }
                    break;

                case 'image':
                    $lines[] = 'Print do site: '.($block['image_url'] ?? '');
                    break;

                case 'actions':
                    // Dos botões em diante é o que o cliente precisa para agir: não pode ser cortado.
                    $links ??= count($lines);

                    foreach ($block['elements'] ?? [] as $button) {
                        if (isset($button['url'])) {
                            $lines[] = ($button['text']['text'] ?? 'Abrir').': '.$button['url'];
                        }
                    }
                    break;

                case 'divider':
                    $lines[] = '';
                    break;
            }
        }

        $join = fn (array $part) => trim(preg_replace("/\n{3,}/", "\n\n", implode("\n\n", $part)));
        $head = $join(array_slice($lines, 0, $links));
        $tail = $links === null ? '' : $join(array_slice($lines, $links));
        $text = Str::limit($head, max(500, self::MAX - mb_strlen($tail) - 2)).($tail !== '' ? "\n\n".$tail : '');

        return [self::KEY => trim($text) ?: (string) ($payload['text'] ?? '')];
    }

    /** mrkdwn → texto: "*negrito*" → "negrito", "<url|rótulo>" → "rótulo (url)", bloco de código sem as crases, entidades de volta. */
    private static function plain(string $mrkdwn): string
    {
        $text = preg_replace('/```\n?(.*?)\n?```/s', '$1', $mrkdwn);
        $text = preg_replace_callback('/<([^|>]+)\|([^>]+)>/', fn ($m) => "{$m[2]} ({$m[1]})", $text);
        $text = preg_replace('/<(https?:[^>]+)>/', '$1', $text);
        $text = preg_replace('/(^|[\s(])[*_~]([^*_~\n]+)[*_~](?=$|[\s).,:;!?])/u', '$1$2', $text);
        $text = str_replace(':white_check_mark:', '✅', $text);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }
}
