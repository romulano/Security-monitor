<?php

namespace App\Services\Company;

/**
 * Quem a conta é para a visibilidade, lido do banco na hora (Visibility::viewer): conta pessoal, admin da empresa,
 * conta de setor (responsável ou membro) ou conta de empresa que agora não vê nada (desativada, empresa suspensa ou
 * com a chave desligada).
 */
final readonly class Viewer
{
    public const PERSONAL = 'personal';

    public const ADMIN = 'admin';

    public const SECTORS = 'sectors';

    public const NOTHING = 'nothing';

    private function __construct(public string $kind, public int $userId, public ?int $companyId) {}

    public static function personal(int $userId): self
    {
        return new self(self::PERSONAL, $userId, null);
    }

    public static function admin(int $userId, int $companyId): self
    {
        return new self(self::ADMIN, $userId, $companyId);
    }

    public static function sectors(int $userId, int $companyId): self
    {
        return new self(self::SECTORS, $userId, $companyId);
    }

    public static function nothing(int $userId, int $companyId): self
    {
        return new self(self::NOTHING, $userId, $companyId);
    }
}
