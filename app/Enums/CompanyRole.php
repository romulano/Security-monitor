<?php

namespace App\Enums;

/**
 * O papel da conta na empresa (§4.3). A admin é uma só por empresa e é responsável por todos os
 * setores (D3); o responsável e o membro veem o que é dos seus setores e o que não tem setor.
 */
enum CompanyRole: string
{
    case Admin = 'admin';
    case SectorManager = 'sector_manager';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::SectorManager => 'Responsável do setor',
            self::Member => 'Membro',
        };
    }
}
