<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Código do aparelho visto num IP num dia (DeviceSightings). Sem vínculo com
 * conta: não ligue a users nem a page_views sem rever a /privacidade.
 */
class DeviceSighting extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'hits' => 'integer',
        ];
    }
}
