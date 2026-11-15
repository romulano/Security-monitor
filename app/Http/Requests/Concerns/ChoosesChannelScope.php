<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\ActionRefused;
use App\Services\Company\ChannelScope;
use App\Services\Company\CompanyChannels;

/**
 * Onde o canal novo recebe (E5a2, DESENHO-5.4 §2.4): `setor` = `geral` (os canais gerais da empresa) ou o id de um setor.
 * A conta pessoal não diz (nulo: o de sempre). A de empresa precisa dizer, e só onde configura: os gerais, só quem
 * administra; um setor, quem administra e quem responde por ele (P2). Fora disso, 404, sem dizer se o setor existe.
 */
trait ChoosesChannelScope
{
    private ?ChannelScope $chosenScope = null;

    public function channelScope(): ?ChannelScope
    {
        if (! $this->user()->isCompanyAccount()) {
            return null;
        }

        $choice = $this->input('setor');

        return $this->chosenScope ??= app(CompanyChannels::class)->scope($this->user(), is_scalar($choice) ? (string) $choice : null);
    }

    /** A conta pessoal sempre; a de empresa, com um escopo que ela configura. */
    protected function choosesManagedScope(): bool
    {
        try {
            $scope = $this->channelScope();
        } catch (ActionRefused) {
            return false;
        }

        return $scope === null || app(CompanyChannels::class)->manages($this->user(), $scope);
    }
}
