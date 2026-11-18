<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um .txt da pasta de vazamentos que o admin mandou carregar. */
class LeakDump extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'path', 'source', 'bytes', 'status', 'tally', 'error', 'removed',
        'requested_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'tally' => 'array',
        'removed' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** O nome do dump a partir do arquivo: `vazamentos/amostra2026.txt` vira `amostra2026`. */
    public static function sourceFromPath(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/[^\p{L}\p{N}._#() -]+/u', '-', $name) ?? $name;

        return mb_substr(trim($name, ' -') ?: 'dump', 0, 64);
    }
}
