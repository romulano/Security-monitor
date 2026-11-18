<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma credencial de empresa vista num dump público. Webmail genérico não chega aqui: a carga
 * descarta pela lista de Admin → Vazamentos.
 */
class LeakedCredential extends Model
{
    use HasFactory;

    /** A linha é um fato imutável: não há o que atualizar. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'domain', 'email', 'password', 'url', 'site_host', 'country', 'source', 'fingerprint', 'leaked_at',
    ];

    /** A senha em claro nunca sai em JSON por acidente. */
    protected $hidden = ['password'];

    protected $casts = ['leaked_at' => 'datetime'];

    /** A chave de deduplicação dentro de um mesmo dump. */
    public static function fingerprintFor(?string $url, string $login, string $password): string
    {
        return md5(($url ?? '').'|'.$login.'|'.$password);
    }
}
