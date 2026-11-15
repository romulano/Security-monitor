<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma linha da auditoria da empresa (CompanyAudit): só acréscimo, o banco recusa mudar ou apagar. */
class CompanyAuditEntry extends Model
{
    protected $table = 'company_audit';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
