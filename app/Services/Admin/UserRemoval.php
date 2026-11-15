<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Services\Alerts\Discord\DiscordConnector;
use App\Services\Alerts\ProfileIndex;
use App\Services\Alerts\Slack\SlackConnector;
use App\Services\Alerts\Telegram\TelegramConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin → remover um usuário: a conta e tudo o que está ligado a ela saem, e a
 * pessoa perde o acesso na hora. Sem a conta não há como entrar de novo,
 * nem por senha nem pelo GitHub; com o cadastro aberto, ela pode criar uma conta
 * nova, que nasce vazia e pede o e-mail confirmado de novo.
 */
class UserRemoval
{
    public function __construct(
        private ProfileIndex $index,
        private SlackConnector $slack,
        private DiscordConnector $discord,
        private TelegramConnector $telegram,
    ) {}

    public function remove(User $target, User $admin): void
    {
        if ($target->is($admin)) {
            throw new ActionRefused('Você não pode remover a própria conta.');
        }

        if ($target->is_admin) {
            throw new ActionRefused('Administradores não são removidos por aqui.');
        }

        $this->erase($target);
    }

    /**
     * Apaga a conta sem as conferências do admin: a limpeza de fora do banco e a cascata. Também a usa a poda das
     * contas de empresa desativadas há 30 dias (CompanyRemoval::purge, §4.6/D9).
     */
    public function erase(User $target): void
    {
        $this->cleanOutside($target);

        // O banco apaga o resto em cascata: alertas, respostas, achados, entregas, canais e o monitoramento do GitHub.
        // Os códigos de aparelho (device_sightings) não entram: são só de quem navega sem login e não têm vínculo
        // com conta, por decisão do dono e pela /privacidade. Não ligue a tabela à conta sem rever a /privacidade.
        // O convite que a pessoa usou, do tempo do cadastro só por convite, fica na tabela invites, sem dono.
        DB::transaction(function () use ($target) {
            $target->endSessions();
            $target->delete();
        });
    }

    /**
     * O que mora fora do banco: as consultas do percolator, os prints do Radar no
     * disco e as conexões com Slack, Discord e Telegram. Se um desses falhar a
     * conta sai mesmo assim: quem foi removido não pode continuar entrando por causa
     * de um serviço de fora fora do ar.
     */
    private function cleanOutside(User $target): void
    {
        $steps = [
            ...$target->alertProfiles->map(fn ($profile) => fn () => $this->index->forget($profile))->all(),
            ...$target->lookalikeWatches->map(fn ($watch) => fn () => $watch->deleteFiles())->all(),
            ...$target->notificationChannels->map(fn ($channel) => fn () => match ($channel->type) {
                'slack' => $this->slack->disconnect($channel),
                'discord' => $this->discord->disconnect($channel),
                'telegram' => $this->telegram->disconnect($channel),
                // O webhook não tem nada fora do banco: a cascata leva o canal e a cópia da posse.
                default => null,
            })->all(),
        ];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                Log::warning('Usuário removido com limpeza externa incompleta', ['user_id' => $target->id, 'erro' => $e->getMessage()]);
            }
        }
    }
}
