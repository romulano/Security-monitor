<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um domínio (ou marca) de e-mail genérico que a carga de vazamentos descarta. Quem edita é o admin,
 * em Admin → Vazamentos → E-mails genéricos.
 */
class FreeMailDomain extends Model
{
    use HasFactory;

    protected $fillable = ['kind', 'value', 'note', 'created_by'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
