<?php

namespace App\Services\Company;

use App\Models\NotificationChannel;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Para onde vai o aviso de um item (R11, D5; DESENHO-5.4 §2.5) — o ponto único que os roteadores, os reenvios, o resumo
 * diário e o agrupamento usam:
 *
 * - item pessoal: os canais de quem o criou (como sempre);
 * - item da empresa sem setor: os canais gerais da empresa;
 * - item do setor S: os canais de S, e também os gerais se S tem "copiar para os gerais" (desligado por padrão, D5).
 *
 * Quem criou o item não decide mais nada (fim do "alerta mudo"): o aviso do setor sai pelos canais do setor mesmo que o
 * criador tenha saído dele ou sido removido. A empresa suspensa ou desligada não recebe nada (D7, DeliveryGate).
 */
class Destinations
{
    public function __construct(private Visibility $visibility, private Companies $companies) {}

    /** Os canais que recebem este item (raiz ou filho), na ordem de sempre (Telegram, Slack, Discord, webhooks). @return Collection<int, NotificationChannel> */
    public function for(Model $item): Collection
    {
        $root = $this->visibility->rootOf($item);

        if ($root === null) {
            return new Collection;
        }

        if ($root->getAttribute('company_id') === null) {
            return $root->user?->notificationChannels ?? new Collection;
        }

        return $this->ofScope((int) $root->getAttribute('company_id'), $root->getAttribute('sector_id'))
            ->orderByRaw("type = 'webhook'")->orderBy('type', 'desc')->orderBy('id')->get();
    }

    /** Os canais de um escopo da empresa: os do setor (mais os gerais, com a cópia) ou os gerais. */
    public function ofScope(int $companyId, ?int $sectorId): Builder
    {
        $copy = $sectorId !== null && (bool) Sector::query()->whereKey($sectorId)->value('copy_to_general');

        return NotificationChannel::query()->where('company_id', $companyId)
            ->where(fn (Builder $q) => $q->when($sectorId === null || $copy, fn ($q) => $q->orWhereNull('sector_id'))
                ->when($sectorId !== null, fn ($q) => $q->orWhere('sector_id', $sectorId)));
    }

    /**
     * As raízes (alertas, Radares, monitoramentos) que mandam para este canal, para o resumo diário: no pessoal, as que
     * o dono criou (como sempre); no geral, as sem setor da empresa e as dos setores com a cópia; no do setor, as dele.
     *
     * @param  Builder<Model>  $query  a consulta da raiz (AlertProfile, LookalikeWatch, GithubMonitor)
     */
    public function sourcesFor(NotificationChannel $channel, Builder $query): Builder
    {
        if (! $channel->isCompany()) {
            return $query->where('user_id', $channel->user_id)->whereNull('company_id');
        }

        $query->where('company_id', $channel->company_id);

        return $channel->sector_id !== null
            ? $query->where('sector_id', $channel->sector_id)
            : $query->where(fn (Builder $q) => $q->whereNull('sector_id')
                ->orWhereIn('sector_id', Sector::query()->where('company_id', $channel->company_id)->where('copy_to_general', true)->select('id')));
    }

    /** O item cabe neste canal (o aviso já na fila: o item mudou de setor, a cópia foi desligada)? */
    public function reaches(NotificationChannel $channel, Model $item): bool
    {
        return $this->for($item)->contains('id', $channel->id);
    }

    /** As raízes do mesmo destino que esta (a mesma chave), em SQL: a conta pessoal, ou a empresa e o mesmo setor (ou sem setor). */
    public function sameDestination(Builder $query, Model $root): Builder
    {
        $company = $root->getAttribute('company_id');
        $sector = $root->getAttribute('sector_id');

        return $company === null
            ? $query->where($query->qualifyColumn('user_id'), $root->getAttribute('user_id'))->whereNull($query->qualifyColumn('company_id'))
            : $query->where($query->qualifyColumn('company_id'), $company)
                ->when($sector === null, fn ($q) => $q->whereNull($q->qualifyColumn('sector_id')), fn ($q) => $q->where($q->qualifyColumn('sector_id'), $sector));
    }

    /** A chave do destino, para juntar numa mensagem só e para a anti-repetição: "u:{conta}" ou "c:{empresa}:{setor|g}". */
    public function key(Model $item): string
    {
        $root = $this->visibility->rootOf($item);

        return $root?->getAttribute('company_id') === null
            ? 'u:'.$root?->getAttribute('user_id')
            : 'c:'.$root->getAttribute('company_id').':'.($root->getAttribute('sector_id') ?? 'g');
    }
}
