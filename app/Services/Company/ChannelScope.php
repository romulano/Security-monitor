<?php

namespace App\Services\Company;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onde um canal de aviso recebe (R11, DESENHO-5.4 §2.1): a conta pessoal (o de sempre), os **gerais** da empresa (o que
 * não tem setor) ou um **setor**. Só diz o escopo; quem pode configurar nele é o CompanyChannels.
 */
final readonly class ChannelScope
{
    private function __construct(public ?int $userId, public ?int $companyId, public ?int $sectorId) {}

    public static function personal(int $userId): self
    {
        return new self($userId, null, null);
    }

    public static function general(int $companyId): self
    {
        return new self(null, $companyId, null);
    }

    public static function sector(int $companyId, int $sectorId): self
    {
        return new self(null, $companyId, $sectorId);
    }

    public static function of(NotificationChannel $channel): self
    {
        return match (true) {
            $channel->company_id === null => self::personal((int) $channel->user_id),
            $channel->sector_id === null => self::general((int) $channel->company_id),
            default => self::sector((int) $channel->company_id, (int) $channel->sector_id),
        };
    }

    public function isPersonal(): bool
    {
        return $this->companyId === null;
    }

    public function isGeneral(): bool
    {
        return $this->companyId !== null && $this->sectorId === null;
    }

    /** O limite da empresa que conta os canais deste escopo (D1): 5 gerais, 5 por setor. Nulo na conta pessoal. */
    public function limitName(): ?string
    {
        return match (true) {
            $this->isPersonal() => null,
            $this->isGeneral() => 'general_channels',
            default => 'sector_channels',
        };
    }

    /** Os canais deste escopo. */
    public function constrain(Builder $query): Builder
    {
        return match (true) {
            $this->isPersonal() => $query->where('user_id', $this->userId)->whereNull('company_id'),
            $this->isGeneral() => $query->where('company_id', $this->companyId)->whereNull('sector_id'),
            default => $query->where('company_id', $this->companyId)->where('sector_id', $this->sectorId),
        };
    }

    /** As colunas de dono de um canal novo deste escopo, com quem conectou. @return array{user_id: int, company_id: ?int, sector_id: ?int} */
    public function columns(int $connectedBy): array
    {
        return ['user_id' => $connectedBy, 'company_id' => $this->companyId, 'sector_id' => $this->sectorId];
    }
}
