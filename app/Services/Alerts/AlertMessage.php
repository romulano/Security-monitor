<?php

namespace App\Services\Alerts;

use App\Models\AlertMatch;
use App\Models\AlertProfile;
use App\Models\NewsCase;
use App\Services\Company\Destinations;
use Illuminate\Support\Str;

/**
 * O conteúdo de um alerta, igual em qualquer app: título no idioma do
 * perfil, texto, fatos do caso e o nome dos alertas que casaram. Cada app
 * (Slack, Discord) só decide o formato.
 */
abstract class AlertMessage
{
    /** Markdown da IA em texto corrido, cortado em palavra. */
    public function plain(string $markdown, int $limit): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $markdown);
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = preg_replace('/^\s*[-*]\s+/m', '• ', $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));

        return Str::limit($text, $limit, '…', preserveWords: true);
    }

    /** O que aconteceu de novo (atualização de caso) ou o resumo do caso. */
    protected function body(AlertMatch $match, bool $pt): ?string
    {
        $case = $match->case;
        $body = $match->kind === 'case_update' && $match->article
            ? ($pt ? ($match->article->new_context_pt ?: $match->article->new_context) : $match->article->new_context)
            : null;

        return $body ?: ($pt ? ($case->summary_pt ?: $case->summary) : $case->summary);
    }

    /** @return array<string, string> Fontes, primeira notícia, CVE, produtos e exploit público, só os que existem. */
    protected function facts(AlertMatch $match): array
    {
        $case = $match->case;

        return array_filter([
            'Fontes' => (string) max(1, (int) $case->source_count),
            'Primeira notícia' => $case->first_at?->timezone($this->tz($match->profile))->format('d/m · H:i'),
            'CVE' => ($cves = $case->indicatorValues('cves')) ? implode(', ', array_slice($cves, 0, 3)) : null,
            'Produtos' => ($products = $case->indicatorValues('affected_products')) ? implode(', ', array_slice($products, 0, 3)) : null,
            'Exploit público' => $this->exploit($case),
        ]);
    }

    /** "EDB-52473 (+1)": o primeiro exploit da matéria, com a contagem dos outros. */
    protected function exploit(NewsCase $case): ?string
    {
        $ids = $case->exploits()->credible()->showcase()->pluck('exploits.id');

        return $ids->isEmpty() ? null : 'EDB-'.$ids->first().($ids->count() > 1 ? ' (+'.($ids->count() - 1).')' : '');
    }

    /**
     * "Loja virtual + App": o alerta da mensagem e os outros do mesmo destino
     * (o cliente, ou o mesmo setor da empresa) que casaram com a mesma notícia
     * (registrados como `merged`). Nulo para quem tem um alerta só.
     */
    protected function alertNames(AlertMatch $match): ?string
    {
        return $match->profile->hasSiblings() ? implode(' + ', $this->alertList($match)) : null;
    }

    /**
     * Os mesmos nomes em lista, também para quem tem um alerta só (o webhook manda
     * sempre o nome do alerta; os apps só quando há mais de um).
     *
     * @return list<string>
     */
    protected function alertList(AlertMatch $match): array
    {
        $profile = $match->profile;

        if (! $profile->hasSiblings()) {
            return [$profile->displayName()];
        }

        $others = AlertMatch::query()
            ->where('case_id', $match->case_id)
            ->where('article_id', $match->article_id)
            ->where('delivery_note', 'merged')
            ->whereKeyNot($match->id)
            // Os do mesmo destino: na empresa, nunca o nome de um alerta de outro setor (Q2 do 5.2).
            ->whereHas('profile', fn ($q) => app(Destinations::class)->sameDestination($q, $profile))
            ->with('profile:id,name,company_name')
            ->get()
            ->map(fn (AlertMatch $m) => $m->profile->displayName());

        return collect([$profile->displayName()])->merge($others)->unique()->values()->all();
    }

    protected function title(NewsCase $case, bool $pt): string
    {
        return (string) ($pt ? ($case->title_pt ?: $case->title) : $case->title);
    }

    protected function level(?string $criticity): string
    {
        return strtoupper($criticity ?: 'sem grau');
    }

    /** O fuso da mensagem: o do primeiro canal do alerta (os do criador, na conta pessoal; os do escopo, na empresa). */
    protected function tz(AlertProfile $profile): string
    {
        return app(Destinations::class)->for($profile)->first()?->timezone ?: 'America/Sao_Paulo';
    }
}
