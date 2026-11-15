<?php

namespace App\Http\Requests\Concerns;

use App\Models\NotificationChannel;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;

/**
 * O canal das telas do Slack, do Discord e do Telegram (E5a2, DESENHO-5.4 §2.4). A conta pessoal tem um de cada app e
 * a tela é o dele, como sempre. A de empresa pode ter vários (P4), então a tela diz qual: `?canal={id}` abre um que a
 * conta configura, e `?setor=geral|{id}` conecta um novo onde ela configura. Sem nenhum dos dois, ou fora do que a conta
 * configura (o membro, outro setor, outra empresa), 404 — nada de "o primeiro".
 */
trait TargetsChannel
{
    use ChoosesChannelScope;

    private ?NotificationChannel $targetedChannel = null;

    /** O app da tela (slack, discord, telegram). */
    abstract protected function channelApp(): string;

    /** O canal desta tela: o pessoal do app ou o `?canal=` que a conta de empresa configura. Sempre do banco. */
    public function channel(): ?NotificationChannel
    {
        if (! $this->user()->isCompanyAccount()) {
            return NotificationChannel::query()->where('user_id', $this->user()->id)->whereNull('company_id')->where('type', $this->channelApp())->first();
        }

        $id = $this->input('canal');

        return $this->targetedChannel ??= is_scalar($id) && ctype_digit((string) $id)
            ? NotificationChannel::query()->manageableBy($this->user())->where('type', $this->channelApp())->find((int) $id)
            : null;
    }

    /** A conta de empresa chega pelo `?canal=` ou pelo `?setor=` que configura; a pessoal, sempre. */
    protected function targetsManagedChannel(): bool
    {
        if (! $this->user()->isCompanyAccount()) {
            return true;
        }

        return $this->filled('canal') ? $this->channel() !== null : $this->filled('setor') && $this->choosesManagedScope();
    }

    /** O que os links e formulários da tela levam adiante: o canal ou o escopo; nada na conta pessoal. @return array<string, int|string> */
    public function target(): array
    {
        return match (true) {
            ! $this->user()->isCompanyAccount() => [],
            $this->channel() !== null => ['canal' => $this->channel()->id],
            default => ['setor' => (string) $this->input('setor')],
        };
    }

    /** O canal desta tela existe e a conta o configura (testar, apagar, preferências). A pessoal sem canal: o de sempre. */
    protected function managesTargetedChannel(): bool
    {
        return ! $this->user()->isCompanyAccount() || $this->channel() !== null;
    }

    /**
     * O que os conectores recebem (CompanyChannels::target): na empresa, o canal existente ou, sem ele, o escopo novo;
     * na pessoal, nada (o forUser de sempre). @return array{0: ?ChannelScope, 1: ?NotificationChannel}
     */
    public function connectorTarget(): array
    {
        if (! $this->user()->isCompanyAccount()) {
            return [null, null];
        }

        return $this->channel() !== null ? [null, $this->channel()] : [$this->channelScope(), null];
    }

    /** Onde o canal da empresa recebe, para a tela dizer: o setor (nulo nos gerais); nulo na conta pessoal. @return array{sector: ?string}|null */
    public function where(): ?array
    {
        if (! $this->user()->isCompanyAccount()) {
            return null;
        }

        $scope = $this->channel() !== null ? ChannelScope::of($this->channel()) : $this->channelScope();

        return ['sector' => app(CompanyChannels::class)->sectorLabel($scope)];
    }
}
