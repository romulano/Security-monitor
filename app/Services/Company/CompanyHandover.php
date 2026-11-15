<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Models\CompanyMember;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A poda dos 30 dias (§4.6, D9) não leva o que é da empresa (P7, DESENHO-5.2 §2.5): antes de apagar a conta, os
 * alertas de Notícias, os Radares e os monitoramentos do GitHub da empresa que ela criou, os domínios deles e a posse
 * dos domínios da empresa que ela cadastrou (`owned_domains`, DESENHO-5.3 §2.8) passam para a admin. Sem isso a cascata
 * de `user_id` apagaria tudo junto com a conta. A prova da empresa não depende de quem cadastrou (o token é da
 * empresa), então nada mais muda. Os canais de aviso da empresa que ela conectou (E5a, DESENHO-5.4 §2.7), as cópias da
 * posse dos webhooks deles e o histórico de envios desses canais também passam: o canal é da empresa, não de quem o
 * conectou. O que é da conta pessoal continua com a cascata.
 */
class CompanyHandover
{
    private const ROOTS = ['alert_profiles', 'lookalike_watches', 'github_monitors'];

    /** Passa para a admin o que a conta criou na empresa; chame dentro da transação da poda. @return int quantas linhas */
    public function toAdmin(CompanyMember $gone): int
    {
        $admin = CompanyMember::query()->where('company_id', $gone->company_id)->where('role', CompanyRole::Admin)->lockForUpdate()->value('user_id');

        if ($admin === null || $admin === $gone->user_id) {
            throw new LogicException('A empresa precisa de outra conta admin para receber o que a conta apagada criou.');
        }

        // A posse primeiro: sem ela, a próxima conferência das cópias derrubaria o Radar e a descoberta (DESENHO-5.3 §1.4).
        $moved = DB::table('owned_domains')->where('user_id', $gone->user_id)->where('company_id', $gone->company_id)->update(['user_id' => $admin]);

        // Depois as cópias: a do Radar e a do alerta somem com o user_id delas e levariam o Radar junto (cascata).
        $moved += DB::table('monitored_domains')
            ->where('user_id', $gone->user_id)
            ->where(fn ($q) => $q
                ->whereIn('profile_id', DB::table('alert_profiles')->where('company_id', $gone->company_id)->select('id'))
                ->orWhereIn('id', DB::table('lookalike_watches')->where('company_id', $gone->company_id)->select('domain_id')))
            ->update(['user_id' => $admin]);

        foreach (self::ROOTS as $table) {
            $moved += DB::table($table)->where('user_id', $gone->user_id)->where('company_id', $gone->company_id)->update(['user_id' => $admin]);
        }

        // Os canais da empresa: as cópias da posse dos webhooks antes (a cascata levaria a cópia, e o webhook pararia), o
        // histórico de envios (notification_deliveries.user_id também é cascata) e o próprio canal. Os pessoais ficam.
        $channels = DB::table('notification_channels')->where('company_id', $gone->company_id)->select('id');
        $moved += DB::table('monitored_domains')->where('user_id', $gone->user_id)
            ->whereIn('id', DB::table('notification_channels')->where('company_id', $gone->company_id)->whereNotNull('domain_id')->select('domain_id'))
            ->update(['user_id' => $admin]);
        $moved += DB::table('notification_deliveries')->where('user_id', $gone->user_id)->whereIn('channel_id', $channels)->update(['user_id' => $admin]);
        $moved += DB::table('notification_channels')->where('user_id', $gone->user_id)->where('company_id', $gone->company_id)->update(['user_id' => $admin]);

        return $moved;
    }
}
