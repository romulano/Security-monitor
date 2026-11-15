<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma visita do navegador a um domínio do Radar: em cada aparelho (navegador,
 * Android, iPhone), o print ao acessar e o de 10 s depois (só quando a tela
 * mudou), mais o texto e os sinais da página.
 */
class LookalikeCapture extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    public const DEVICES = ['desktop' => 'Navegador', 'android' => 'Android', 'iphone' => 'iPhone'];

    public const DEVICE_HINTS = ['desktop' => 'Chrome no Windows', 'android' => 'Chrome no Android', 'iphone' => 'Safari no iPhone'];

    public const MOMENTS = ['first' => 'Ao acessar', 'second' => 'Após 10 s'];

    /** Erro de certificado do Chromium → o que o visitante leria. */
    public const CERTIFICATE_PROBLEMS = [
        'net::ERR_CERT_DATE_INVALID' => 'vencido ou ainda não válido',
        'net::ERR_CERT_COMMON_NAME_INVALID' => 'emitido para outro nome',
        'net::ERR_CERT_AUTHORITY_INVALID' => 'de emissor não confiável (autoassinado, por exemplo)',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'captured_at' => 'datetime',
            'data' => 'array',
            'shots' => 'array',
            'keep' => 'boolean',
        ];
    }

    public function lookalike(): BelongsTo
    {
        return $this->belongsTo(LookalikeDomain::class, 'lookalike_id');
    }

    /** Caminho (relativo a shots/) do print de um aparelho e momento. */
    public function shot(string $device, string $moment = 'first'): ?string
    {
        $path = $this->shots[$device][$moment] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function hasShot(string $device = 'desktop', string $moment = 'first'): bool
    {
        return $this->shot($device, $moment) !== null;
    }

    /** O print mais recente do aparelho: o de 10 s depois quando a tela mudou, senão o primeiro. */
    public function latestMoment(string $device): ?string
    {
        return match (true) {
            $this->hasShot($device, 'second') => 'second',
            $this->hasShot($device, 'first') => 'first',
            default => null,
        };
    }

    /** A tela mudou nos 10 s na página? (aí os dois prints valem) */
    public function changed(string $device): bool
    {
        return (bool) ($this->shots[$device]['changed'] ?? false) && $this->hasShot($device, 'second');
    }

    /** Aparelhos com pelo menos um print, na ordem navegador, Android, iPhone. @return list<string> */
    public function devicesWithShots(): array
    {
        return array_values(array_filter(array_keys(self::DEVICES), fn ($d) => $this->hasShot($d)));
    }

    /**
     * O certificado HTTPS não passou na checagem do navegador (a página só abriu porque o Radar ignora
     * o aviso): o motivo em português, ou nulo se o certificado estava em ordem.
     */
    public function certificateProblem(): ?string
    {
        $code = $this->data['certificate_error'] ?? null;

        return $code ? (self::CERTIFICATE_PROBLEMS[$code] ?? 'inválido') : null;
    }

    /** O que a página mostrou num aparelho (título, formulários, texto). */
    public function device(string $device): array
    {
        return (array) ($this->data['devices'][$device] ?? []);
    }

    /** Caminho do print no disco, sempre dentro de shots/ da pasta do navegador. */
    public function shotFile(string $device, string $moment = 'first'): ?string
    {
        return self::fileIn($this->shot($device, $moment));
    }

    public static function fileIn(?string $relative): ?string
    {
        if (! $relative) {
            return null;
        }

        $base = realpath(rtrim((string) config('secmon.lookalikes.browser_dir'), '/').'/shots');
        $file = $base ? realpath($base.'/'.ltrim($relative, '/')) : false;

        return $file && str_starts_with($file, $base.'/') ? $file : null;
    }

    /** Todos os arquivos de print desta visita (para apagar junto). @return list<string> */
    public function files(): array
    {
        $files = [];

        foreach (array_keys(self::DEVICES) as $device) {
            foreach (array_keys(self::MOMENTS) as $moment) {
                if ($file = $this->shotFile($device, $moment)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    public function visibilityRoot(): ?Model
    {
        return $this->lookalike?->watch;
    }

    /** As visitas (prints) das imitações que a conta vê. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('lookalike_id'), LookalikeDomain::query()->visibleTo($user)->select('id'));
    }
}
