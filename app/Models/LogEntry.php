<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um registro do diário de bordo da /sobre: o dia, o título e o que mudou. */
class LogEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['logged_on' => 'date'];
    }
}
