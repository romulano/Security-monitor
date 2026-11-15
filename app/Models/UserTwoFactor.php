<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A 2FA de uma conta (TwoFactor): o segredo cifrado, a confirmação e os hashes dos códigos de recuperação. */
class UserTwoFactor extends Model
{
    protected $table = 'user_two_factor';

    /** Ligar, o segredo e os códigos só pelo TwoFactor (forceFill e gravações condicionais), nunca de um array. */
    protected $guarded = ['*'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_step' => 'integer',
            'recovery_codes' => 'array',
        ];
    }

    public function confirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
