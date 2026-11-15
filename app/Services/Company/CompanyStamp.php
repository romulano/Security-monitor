<?php

namespace App\Services\Company;

use Illuminate\Support\Facades\DB;

/**
 * O carimbo retroativo (E2g; a posse dos domínios, E3a2, em domains()): o alerta, o Radar e o monitoramento do GitHub que uma conta de empresa criou antes das PRs
 * de leitura (E2c/E2d) ficaram com `company_id` nulo, e o Visibility não os mostra a ninguém (nem a quem criou). Aqui
 * eles ganham a empresa do `company_user` de quem criou, sem setor (a empresa toda vê; quem administra configura).
 *
 * - Conta pessoal: nada (não tem linha em `company_user`).
 * - Uma vez só: só o que ainda está nulo; o trigger da E2b0 recusa mudar a empresa depois e confere que é a do dono.
 * - GitHub: o único parcial da E2b0 vale só para `company_id` nulo, e uma conta tem no máximo um monitoramento nulo;
 *   carimbar tira esse da regra e nunca a fura.
 * A migration 2026_12_13_000100 faz o mesmo no deploy (SQL igual); o comando `secmon:company-stamp` repete quando preciso.
 *
 * Sem setor de propósito (decisão do coordenador, 08/11): quem administra a empresa move o item depois, se quiser.
 * Volume: um `update` por tabela, numa transação. Com milhões de itens a carimbar, rode o comando em lotes (por faixa
 * de id) em vez de deixar para a migration, para não segurar a trava das tabelas no deploy. Hoje não há empresa em
 * produção, então a migration não acha nada.
 */
class CompanyStamp
{
    public const TABLES = ['alert_profiles' => 'alertas', 'lookalike_watches' => 'radares', 'github_monitors' => 'github'];

    /** @return array{alertas: int, radares: int, github: int} quantos foram (ou seriam, com `$dryRun`) carimbados */
    public function run(bool $dryRun = false): array
    {
        $counts = [];

        DB::transaction(function () use ($dryRun, &$counts) {
            foreach (self::TABLES as $table => $label) {
                $counts[$label] = $dryRun
                    ? DB::table($table.' as t')->join('company_user as cu', 'cu.user_id', '=', 't.user_id')->whereNull('t.company_id')->count()
                    : DB::update("update {$table} t set company_id = cu.company_id from company_user cu where cu.user_id = t.user_id and t.company_id is null");
            }
        });

        return $counts;
    }

    /**
     * A posse dos domínios (E3a2): o que uma conta de empresa comprovou antes da E3a1 ficou como posse pessoal dela
     * (`company_id` nulo), e os itens da empresa já não a enxergam (a prova da empresa é só a dela). Aqui cada nome passa
     * para a empresa de quem cadastrou, sem setor (a empresa toda vê; quem administra move depois, como na E2g). Só o nome
     * que a empresa já tem ou que um alerta ou Radar da empresa usa (ele ou um subdomínio dele): o resto é da pessoa e fica na conta.
     *
     * O nome repetido dentro da mesma empresa (duas contas, ou já cadastrado pela empresa) fica **uma** linha, porque o
     * único da E3a0 é por empresa e nome:
     * - se a empresa já tem o nome, fica a dela, e as pessoais saem (o token delas não prova nada para a empresa);
     * - senão fica a **comprovada** mais antiga (é a que mantém os alertas e Radares de pé), ou, sem nenhuma comprovada,
     *   a mais antiga (o primeiro pedido). As outras saem: só levavam tokens de conta, que a empresa não aceita.
     *
     * A linha que fica perde os tokens da conta (`tokens`, `proof_token`, `legacy`) e é conferida de novo na próxima
     * rodada (`checked_at` nulo): a prova continua por enquanto, e se o TXT da **empresa** não aparecer, cai em 7 dias
     * como qualquer outra (o e-mail da E3d avisa). As cópias dos itens da empresa passam a mostrar o token da empresa.
     *
     * Não confere o limite de domínios (R5): numa empresa que já passou dos 15 com as contas, o carimbo deixa a empresa
     * acima do limite, e ela só cadastra outro depois de remover. Pendência anotada no 5.3 (Red Team da E3a2).
     *
     * @return array{dominios: int, repetidos: int} quantos nomes passaram para a empresa e quantas linhas repetidas saíram
     */
    public function domains(bool $dryRun = false): array
    {
        $rows = DB::table('owned_domains as o')->join('company_user as cu', 'cu.user_id', '=', 'o.user_id')->whereNull('o.company_id')
            // Só o nome que a empresa já tem ou que um alerta ou Radar da empresa usa (ele ou um subdomínio dele): o
            // domínio pessoal de quem trabalha lá (o blog dela) fica na conta, fora da vista da empresa e do limite (Z1 do
            // Red Team da E3a2).
            ->where(fn ($q) => $q
                ->whereExists(fn ($q) => $q->from('owned_domains as oc')->whereColumn('oc.company_id', 'cu.company_id')->whereColumn('oc.domain', 'o.domain'))
                ->orWhereExists(fn ($q) => $q->from('monitored_domains as md')
                    ->where(fn ($q) => $q->whereColumn('md.domain', 'o.domain')->orWhereRaw("md.domain like '%.' || o.domain"))
                    ->where(fn ($q) => $q
                        ->whereIn('md.profile_id', fn ($q) => $q->from('alert_profiles')->select('id')->whereColumn('alert_profiles.company_id', 'cu.company_id'))
                        ->orWhereIn('md.id', fn ($q) => $q->from('lookalike_watches')->select('domain_id')->whereColumn('lookalike_watches.company_id', 'cu.company_id')))))
            ->orderByRaw('o.verified_at asc nulls last')->orderBy('o.id')
            ->get(['o.id', 'o.domain', 'o.verified_at', 'cu.company_id']);
        $counts = ['dominios' => 0, 'repetidos' => 0];

        DB::transaction(function () use ($rows, $dryRun, &$counts) {
            foreach ($rows->groupBy(fn ($row) => $row->company_id.'|'.$row->domain) as $group) {
                $first = $group->first();
                $taken = DB::table('owned_domains')->where('company_id', $first->company_id)->where('domain', $first->domain)->exists();
                $keep = $taken ? null : $first;
                $drop = $group->reject(fn ($row) => $row->id === $keep?->id)->pluck('id');
                $counts['dominios'] += $keep ? 1 : 0;
                $counts['repetidos'] += $drop->count();

                if ($dryRun) {
                    continue;
                }

                DB::table('owned_domains')->whereIn('id', $drop)->delete();

                if ($keep) {
                    DB::table('owned_domains')->where('id', $keep->id)->update([
                        'company_id' => $keep->company_id, 'sector_id' => null, 'tokens' => '[]', 'proof_token' => null,
                        'legacy' => false, 'checked_at' => null, 'updated_at' => now(),
                    ]);
                }

                $this->showCompanyToken((int) $first->company_id, $first->domain);
            }
        });

        return $counts;
    }

    /** As cópias dos alertas e Radares da empresa com o nome (ou um subdomínio dele) passam a mostrar o TXT da empresa. */
    /** A conversa de uma pessoa: o Telegram privado (ou com o ID positivo de uma pessoa) e a mensagem direta do Discord. */
    /** O canal (alias `c`) é da admin ativa da empresa de quem conectou. */
    public const ACTIVE_ADMIN = "exists (select 1 from company_user a where a.user_id = c.user_id and a.role = 'admin' and a.deactivated_at is null)";

    public const PRIVATE_CHANNEL = "(type = 'telegram' and (method = 'private' or (telegram_chat_id is not null and left(telegram_chat_id, 1) <> '-'))) or (type = 'discord' and method = 'dm')";

    /**
     * Os canais de aviso (E5b1, DESENHO-5.4 §2.8): o que uma conta de empresa conectou antes da E5a ficou como canal
     * pessoal dela (`company_id` nulo), e desde o roteamento novo nada da empresa vai para lá. Só o canal da **admin
     * ativa** passa a **geral** da empresa (P2: canal geral só a admin configura; ela move depois, como na E2g). Sai o
     * resto: a conversa privada (Telegram privado, DM do Discord: P1) e o canal de membro, de responsável ou de conta
     * desativada (o Slack de um membro passaria a receber tudo o que é da empresa sem setor; Red Team da E5b1). Não confere
     * o limite de 5 gerais (como o carimbo dos domínios não confere os 15): a empresa acima dele só conecta outro depois
     * de remover. O webhook carimbado reconfere a posse pela empresa na próxima rodada (o geral só vale com domínio sem setor).
     *
     * @return array{canais: int, removidos: int}
     */
    public function channels(bool $dryRun = false): array
    {
        $ofCompany = fn () => DB::table('notification_channels as c')->whereNull('c.company_id')
            ->whereExists(fn ($q) => $q->from('company_user as cu')->whereColumn('cu.user_id', 'c.user_id'));
        $leaves = fn () => $ofCompany()->where(fn ($q) => $q->whereRaw(self::PRIVATE_CHANNEL)->orWhereRaw('not ('.self::ACTIVE_ADMIN.')'));

        if ($dryRun) {
            return ['canais' => $ofCompany()->count() - $leaves()->count(), 'removidos' => $leaves()->count()];
        }

        return DB::transaction(function () use ($leaves) {
            // O que sai, antes: o trigger da E5a0 recusaria carimbar a conversa privada.
            $removed = $leaves()->delete();

            return [
                'canais' => DB::update("update notification_channels c set company_id = cu.company_id, sector_id = null from company_user cu where cu.user_id = c.user_id and c.company_id is null and cu.role = 'admin' and cu.deactivated_at is null"),
                'removidos' => $removed,
            ];
        });
    }

    private function showCompanyToken(int $companyId, string $domain): void
    {
        DB::table('monitored_domains')
            ->where(fn ($q) => $q->where('domain', $domain)->orWhere('domain', 'like', '%.'.$domain))
            ->where(fn ($q) => $q
                ->whereIn('profile_id', DB::table('alert_profiles')->where('company_id', $companyId)->select('id'))
                ->orWhereIn('id', DB::table('lookalike_watches')->where('company_id', $companyId)->select('domain_id')))
            ->update(['verify_token' => DB::table('companies')->where('id', $companyId)->value('domain_token')]);
    }
}
