<?php

namespace App\Http\Requests\Concerns;

use App\Models\NotificationChannel;

/**
 * O canal das telas do Slack, do Discord e do Telegram. A conta tem um de cada app, e a tela é o dele.
 *
 * Os métodos que diziam qual canal da empresa a tela abria continuam aqui, respondendo o caso único, para as telas e
 * os conectores não precisarem saber que não existe mais escolha.
 */
trait TargetsChannel
{
    /** O app da tela (slack, discord, telegram). */
    abstract protected function channelApp(): string;

    /** O canal desta tela: o da conta, sempre do banco. */
    public function channel(): ?NotificationChannel
    {
        return NotificationChannel::query()->where('user_id', $this->user()->id)->where('type', $this->channelApp())->first();
    }

    protected function targetsManagedChannel(): bool
    {
        return true;
    }

    /** O que os links e formulários da tela levam adiante: nada, porque há um canal só por app. @return array<string, int|string> */
    public function target(): array
    {
        return [];
    }

    protected function managesTargetedChannel(): bool
    {
        return true;
    }

    /** O que os conectores recebem depois do spread: nada, e o canal da conta vale (Channels::target). @return array{} */
    public function connectorTarget(): array
    {
        return [];
    }

    /** Onde o canal recebe, para a tela dizer: não existe setor nesta versão. */
    public function where(): ?array
    {
        return null;
    }
}
