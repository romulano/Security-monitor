<?php

namespace App\Services\Enrichment;

use App\Models\Article;
use App\Models\NewsCase;
use Illuminate\Support\Str;

/**
 * Extração sem IA. Não substitui os resumos analíticos do Claude/OpenAI, mas
 * garante que o crawler continue salvando notícia mesmo sem chave configurada
 * ou quando o provedor está fora do ar.
 */
class HeuristicEnricher implements Enricher
{
    private const ACTORS = [
        'ShinyHunters', 'Scattered Spider', 'LockBit', 'Cl0p', 'Clop', 'Akira', 'Qilin',
        'DragonForce', 'BlackCat', 'ALPHV', 'Play', 'Medusa', 'RansomHub', 'Lazarus',
        'Kimsuky', 'APT28', 'APT29', 'APT41', 'Volt Typhoon', 'Salt Typhoon', 'Sandworm',
        'Turla', 'FIN7', 'Conti', 'Evil Corp', 'Lynx', 'INC Ransom', 'Hunters International',
    ];

    private const MALWARE = [
        'Cobalt Strike', 'Mimikatz', 'Mirai', 'Emotet', 'QakBot', 'IcedID', 'RedLine',
        'Raccoon Stealer', 'Lumma', 'Vidar', 'AsyncRAT', 'Remcos', 'XMRig', 'SmokeLoader',
        'Amadey', 'Glupteba', 'Metasploit', 'Meterpreter', 'PlugX', 'Gh0st RAT',
    ];

    private const SECTORS = [
        'healthcare', 'government', 'finance', 'banking', 'education', 'retail', 'energy',
        'telecommunications', 'manufacturing', 'defense', 'aerospace', 'insurance',
        'transportation', 'logistics', 'technology', 'media', 'hospitality', 'legal',
        'automotive', 'water', 'military', 'e-commerce',
    ];

    private const ATTACK_TYPES = [
        'ransomware' => 'ransomware',
        'data breach' => 'data_breach',
        'phishing' => 'phishing',
        'supply chain' => 'supply_chain',
        'ddos' => 'ddos',
        'espionage' => 'espionage',
        'credential' => 'credential_theft',
        'zero-day' => 'zero_day',
        'botnet' => 'botnet',
        'malware' => 'malware_distribution',
    ];

    public function name(): string
    {
        return 'heuristic';
    }

    public function isReady(): bool
    {
        return true;
    }

    public function enrich(Article $article, ?NewsCase $existing = null): EnrichmentResult
    {
        $text = trim(($article->original_title ?? '')."\n".($article->content ?: $article->summary));
        $lower = mb_strtolower($text);

        $raw = [
            'cves' => $this->matchAll('/\bCVE-\d{4}-\d{4,7}\b/i', $text, 20),
            'iocs' => array_merge(
                $this->matchAll('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text, 15),
                $this->matchAll('/\b[a-f0-9]{64}\b/i', $text, 10),
                $this->matchAll('/\b[\w-]+\.(?:exe|dll|ps1|sh|jar|apk|bin)\b/i', $text, 10),
            ),
            'ttps_attack' => $this->matchAll('/\bT\d{4}(?:\.\d{3})?\b/', $text, 20),
            'threat_actors' => $this->dictionary(self::ACTORS, $text),
            'malware_families' => $this->dictionary(self::MALWARE, $text),
            'victim_sectors' => $this->dictionary(self::SECTORS, $lower, caseSensitive: false),
            'authors' => array_filter([$article->author]),
            'attack_type' => $this->attackTypes($lower),
            'source_confidence' => ['news_report'],
        ];

        return new EnrichmentResult(
            title: (string) ($article->original_title ?: 'Sem título'),
            summary: $this->summarize($article),
            criticity: $this->criticity($lower, $raw),
            indicators: EnrichmentResult::indicatorGroups($raw),
            isNewInfo: true,
            newContext: null,
            driver: $this->name(),
        );
    }

    private function matchAll(string $pattern, string $text, int $limit): array
    {
        preg_match_all($pattern, $text, $matches);

        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, $limit);
    }

    private function dictionary(array $needles, string $haystack, bool $caseSensitive = true): array
    {
        $found = [];

        foreach ($needles as $needle) {
            $hit = $caseSensitive
                ? str_contains($haystack, $needle)
                : str_contains($haystack, mb_strtolower($needle));

            if ($hit) {
                $found[] = $needle;
            }
        }

        return $found;
    }

    private function attackTypes(string $lower): array
    {
        $types = [];

        foreach (self::ATTACK_TYPES as $needle => $value) {
            if (str_contains($lower, $needle)) {
                $types[] = $value;
            }
        }

        return array_values(array_unique($types));
    }

    private function criticity(string $lower, array $raw): string
    {
        $critical = ['actively exploited', 'exploited in the wild', 'zero-day', 'cvss 10', 'cvss 9', 'unauthenticated remote code'];
        $high = ['ransomware', 'data breach', 'remote code execution', 'privilege escalation', 'backdoor', 'supply chain'];
        $medium = ['vulnerability', 'patch', 'malware', 'phishing', 'exploit', 'advisory'];

        foreach ($critical as $needle) {
            if (str_contains($lower, $needle)) {
                return 'critical';
            }
        }

        foreach ($high as $needle) {
            if (str_contains($lower, $needle)) {
                return 'high';
            }
        }

        if ($raw['cves'] !== []) {
            return 'high';
        }

        foreach ($medium as $needle) {
            if (str_contains($lower, $needle)) {
                return 'medium';
            }
        }

        return 'low';
    }

    private function summarize(Article $article): string
    {
        $body = trim((string) ($article->content ?: $article->summary));

        if ($body === '') {
            return '_Sem conteúdo extraído da fonte._';
        }

        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $body) ?: []),
            fn ($p) => mb_strlen($p) > 80
        ));

        $lead = implode("\n\n", array_slice($paragraphs, 0, 2)) ?: $body;

        return Str::limit($lead, 1400);
    }
}
