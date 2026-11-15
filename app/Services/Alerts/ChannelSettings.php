<?php

namespace App\Services\Alerts;

use App\Models\AlertProfile;
use App\Models\NotificationChannel;
use App\Models\User;

/** O que as telas do Slack, do Discord e do Telegram têm em comum: a base da página e as preferências. */
class ChannelSettings
{
    /** O que toda página de app recebe: o canal, os alertas prontos, os últimos envios e os fusos. */
    public function page(User $user, ?NotificationChannel $channel, bool $changing): array
    {
        return [
            'channel' => $channel,
            // Os alertas que mandam para este canal: os que a conta criou e ainda vê (até a E5b).
            'profiles' => AlertProfile::query()->visibleTo($user)->where('user_id', $user->id)->whereNotNull('completed_at')->oldest('id')->get(),
            'changing' => $changing,
            'deliveries' => $channel ? $channel->deliveries()->latest()->latest('id')->limit(8)->get() : collect(),
            'timezones' => NotificationChannel::TIMEZONES,
        ];
    }

    /**
     * Tempo real, horário silencioso e resumo diário (o formulário é o mesmo em todos os apps).
     *
     * @param  array{realtime: bool, quiet: bool, quiet_start: ?string, quiet_end: ?string, digest: bool, digest_time: string, digest_scope: string, digest_days: array, timezone: string}  $preferences
     */
    public function save(NotificationChannel $channel, array $preferences): void
    {
        $quiet = $preferences['quiet'];
        $days = array_values(array_unique(array_map('intval', $preferences['digest_days'])));
        sort($days);

        $channel->forceFill([
            'realtime' => $preferences['realtime'],
            'quiet_start' => $quiet ? $preferences['quiet_start'] : null,
            'quiet_end' => $quiet ? $preferences['quiet_end'] : null,
            'digest' => $preferences['digest'],
            'digest_time' => $preferences['digest_time'],
            'digest_scope' => $preferences['digest_scope'],
            'digest_days' => $days,
            'timezone' => $preferences['timezone'],
        ])->save();
    }
}
