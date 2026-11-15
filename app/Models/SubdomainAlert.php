<?php

namespace App\Models;

use App\Models\Contracts\VisibleThroughRoot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aviso do Radar sobre um subdomínio vigiado (E4a): `takeover`, o CNAME aponta para fora e o destino não resolve
 * (quem criar o destino responde pelo subdomínio); `outside`, o subdomínio passou a apontar para fora da zona da
 * empresa. Filho do Radar: quem vê o Radar vê o aviso, e ele sai pelos canais de quem criou o Radar.
 */
class SubdomainAlert extends Model implements VisibleThroughRoot
{
    protected $guarded = [];

    public const KINDS = ['takeover' => 'tomada de subdomínio', 'outside' => 'passou a apontar para fora'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'notified_at' => 'datetime'];
    }

    public function subdomain(): BelongsTo
    {
        return $this->belongsTo(OwnedSubdomain::class, 'owned_subdomain_id');
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(LookalikeWatch::class, 'watch_id');
    }

    public function visibilityRoot(): ?Model
    {
        return $this->watch;
    }
}
