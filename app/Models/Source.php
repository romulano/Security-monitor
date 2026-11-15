<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['proxy_url'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            // Leva usuário e senha do proxy: criptografado, como o bot_token dos notificadores.
            'proxy_url' => 'encrypted',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'feed_changed_at' => 'datetime',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    /**
     * O proxy sem a credencial (`socks5h://host:porta`): é o único formato
     * que pode aparecer na tela ou no log.
     */
    public function proxyLabel(): ?string
    {
        if (! $this->proxy_url) {
            return null;
        }

        $parts = parse_url($this->proxy_url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return 'proxy';
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Está na hora desta fonte rodar de novo? */
    public function isDue(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->last_run_at === null
            || $this->last_run_at->addMinutes(max(5, (int) $this->interval_minutes))->isPast();
    }
}
