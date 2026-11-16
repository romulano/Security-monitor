<?php

namespace App\Models;

use App\Models\Concerns\VisibleToAccount;
use App\Models\Contracts\VisibilityRoot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Radar de domínios de um domínio do cliente: o monitoramento dos nomes
 * parecidos com ele. Nasce na entrevista do Radar (domínio, marca, ativação),
 * separada da de notícias. Instalação de um dono só: o domínio já nasce
 * comprovado, então a primeira varredura começa na ativação, sem espera.
 *
 * `status`: draft (entrevista em andamento), waiting (legado; não é mais
 * produzido), active ou paused.
 */
class LookalikeWatch extends Model implements VisibilityRoot
{
    use VisibleToAccount;

    /** Empresa e setor só pelo serviço (Visibility::stampFor + forceFill), nunca de um array da tela. */
    protected $guarded = [];

    /** Até quantos nomes de marca o cliente informa no passo 2. */
    public const MAX_BRANDS = 5;

    private ?bool $hasSiblings = null;

    protected function casts(): array
    {
        return [
            'last_scan_at' => 'datetime',
            'next_scan_at' => 'datetime',
            'manual_scan_at' => 'datetime',
            'stats' => 'array',
            'free_variants' => 'array',
            'last_log' => 'array',
            'reference' => 'array',
            'reference_at' => 'datetime',
            'brands' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MonitoredDomain::class, 'domain_id');
    }

    public function lookalikes(): HasMany
    {
        return $this->hasMany(LookalikeDomain::class, 'watch_id');
    }

    /** Páginas do site do cliente guardadas como referência. @return list<array{url: string, title: string, shots: array<string, string>}> */
    public function referencePages(): array
    {
        return array_values(array_filter((array) ($this->reference['pages'] ?? []), fn ($p) => is_array($p) && ! empty($p['shots'])));
    }

    /** Prints de referência de um aparelho (relativos a shots/), na ordem das páginas. @return list<string> */
    public function referenceShots(string $device): array
    {
        return array_values(array_filter(array_map(fn ($p) => $p['shots'][$device] ?? null, $this->referencePages())));
    }

    /** Hora de tirar de novo os prints do site do cliente (1 vez a cada `reference_every_days`)? */
    public function referenceDue(): bool
    {
        return ! $this->reference_at || $this->reference_at->lt(now()->subDays((int) config('secmon.lookalikes.reference_every_days', 30)));
    }

    /** Apaga do disco os prints deste Radar (achados e referência); o banco apaga as linhas em cascata. */
    public function deleteFiles(bool $referenceOnly = false): void
    {
        $files = [];

        foreach ($this->referencePages() as $page) {
            foreach ($page['shots'] as $path) {
                $files[] = LookalikeCapture::fileIn($path);
            }
        }

        if (! $referenceOnly) {
            LookalikeCapture::query()->whereIn('lookalike_id', $this->lookalikes()->select('id'))->each(function (LookalikeCapture $capture) use (&$files) {
                array_push($files, ...$capture->files());
            });
        }

        File::delete(array_values(array_filter($files)));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** A entrevista terminou (o cliente clicou em "Ativar o Radar"). */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** O nome da empresa, para o kit de denúncia e para a IA. */
    public function companyName(): string
    {
        return $this->company_name ?: ($this->domain?->domain ?? 'Radar '.$this->id);
    }

    /** @return list<string> os nomes da marca e dos produtos */
    public function brandNames(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->brands ?? []))));
    }

    /**
     * O cliente tem outros Radares além deste? O de empresa sempre leva o `?radar=` na URL: cada conta vê um conjunto
     * diferente, e "sem parâmetro = o primeiro" poderia apontar para outro Radar (DESENHO-5.2 §4.3).
     */
    public function hasSiblings(): bool
    {
        return $this->hasSiblings ??= static::query()->visibleTo($this->user)->whereKeyNot($this->id)->exists();
    }

    /** Parâmetro que diz qual Radar uma URL mira. Quem tem um só continua com as URLs de sempre. */
    public function urlParams(array $extra = []): array
    {
        return $extra + ($this->hasSiblings() ? ['radar' => $this->id] : []);
    }

    /** Quando o "Verificar agora" volta a valer (nulo: já vale). */
    public function manualScanAvailableAt(): ?Carbon
    {
        $at = $this->manual_scan_at?->copy()->addHours((int) config('secmon.lookalikes.manual_scan_hours', 24));

        return $at && $at->isFuture() ? $at : null;
    }
}
