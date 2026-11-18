<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Terceira natureza na lista de e-mail genérico: `prefix`.
 *
 * A regra de marca (`marca.<sufixo público>`) exige que o que vem depois seja TLD conhecido. Isso
 * pega `hotmail.co.uk`, mas não pega o lixo que um dump de stealer produz aos montes:
 * `gmail.comm`, `gmail.con`, `gmail.cominsta`, `gmail.compassword`, `gmail.b`. Eram 7.472 só de
 * `gmail.` no primeiro arquivo carregado.
 *
 * `prefix` casa pelo começo e ignora o que vem depois: `gmail.` pega `gmail.` seguido de qualquer
 * coisa. O valor TEM de terminar em ponto — sem isso, um prefixo `mail` pegaria `mailchimp.com`.
 *
 * ATENÇÃO: prefixo é mais largo que a regra de marca. Um `live.` pegaria também
 * `live.suaempresa.com`, que a regra de marca deixa de fora justamente por ser domínio de empresa.
 * Por isso `live.` e `outlook.` NÃO estão na semente (ver SEGUROS abaixo): se o admin cadastrá-los
 * pela tela, é bom conferir antes quem sairia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        DB::statement('alter table free_mail_domains drop constraint free_mail_domains_kind_check');
        DB::statement("alter table free_mail_domains add constraint free_mail_domains_kind_check check (kind in ('domain', 'brand', 'prefix'))");

        DB::statement('alter table free_mail_domains drop constraint free_mail_domains_shape_check');
        DB::statement(<<<'SQL'
            alter table free_mail_domains add constraint free_mail_domains_shape_check check (
                (kind = 'domain' and value ~ '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$')
                or (kind = 'brand' and value ~ '^[a-z0-9][a-z0-9-]*$')
                or (kind = 'prefix' and value ~ '^[a-z0-9][a-z0-9.-]*\.$')
            )
        SQL);

        // A SEMENTE NÃO SAI DE TODA A LISTA, e isso foi medido, não suposto.
        //
        // Gerar um prefixo do primeiro rótulo de cada entrada produziria `mail.`, `live.`,
        // `outlook.`, `email.`, `cs.`, `usa.` — e prefixo casa pelo começo, sem exigir TLD. Contra
        // as 604 mil credenciais do primeiro dump, esses apagariam justamente o que o produto
        // existe para achar:
        //
        //   mail.    1.610 — mail.universidade.edu.tw, mail.politecnico.it, mail.faculdade.ca, mail.orgao.gov.tw
        //   live.      444 — live.universidade.ac.uk, live.faculdade.edu.ng, live.escola-distrito.org
        //   outlook.   103 — outlook.universidade.ac.uk, outlook.faculdade.edu.ph, outlook.escola.edu.au
        //
        // São universidades, escolas e governo usando `mail.<instituição>` ou um tenant do
        // Microsoft 365 Education. Por isso a semente traz só o que foi conferido linha a linha
        // como lixo (`gmail.`, `hotmail.`) e marcas que hoje não atingem ninguém. O resto o admin
        // cadastra na tela, e tem como conferir antes: buscar `@prefixo` em Credenciais mostra
        // exatamente quem seria apagado.
        $now = now();
        $linhas = [];

        foreach (self::SEGUROS as $value => $nota) {
            $linhas[] = [
                'kind' => 'prefix', 'value' => $value, 'note' => $nota,
                'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('free_mail_domains')->insertOrIgnore($linhas);
    }

    /**
     * Conferidos contra os dados reais: ou só pegam lixo, ou hoje não pegam ninguém.
     *
     * `outlook.` e `live.` FICARAM DE FORA de propósito, embora pedidos por nome: a conferência
     * mostrou universidade neles. Eles entram pela tela se o dono decidir, vendo o impacto antes.
     */
    private const SEGUROS = [
        'gmail.' => 'conferido: 7.450 casos, todos gmail corrompido (gmail.comm, gmail.con, gmail.b)',
        'hotmail.' => 'conferido: 339 casos, todos hotmail corrompido (hotmail.cm, hotmail.ocm)',
        '21cn.' => 'marca de webmail; hoje não atinge ninguém',
        'aliyun.' => 'marca de webmail; hoje não atinge ninguém',
        'club-internet.' => 'marca de webmail; hoje não atinge ninguém',
        'foxmail.' => 'marca de webmail; hoje não atinge ninguém',
        'globomail.' => 'marca de webmail; hoje não atinge ninguém',
        'indiatimes.' => 'marca de webmail; hoje não atinge ninguém',
        'sify.' => 'marca de webmail; hoje não atinge ninguém',
        'sohu.' => 'marca de webmail; hoje não atinge ninguém',
        'superig.' => 'marca de webmail; hoje não atinge ninguém',
        'yahoogroups.' => 'marca de webmail; hoje não atinge ninguém',
        'yandex.' => 'marca de webmail; hoje não atinge ninguém',
        'yeah.' => 'marca de webmail; hoje não atinge ninguém',
    ];

    public function down(): void
    {
        DB::table('free_mail_domains')->where('kind', 'prefix')->delete();

        DB::statement('alter table free_mail_domains drop constraint free_mail_domains_shape_check');
        DB::statement(<<<'SQL'
            alter table free_mail_domains add constraint free_mail_domains_shape_check check (
                (kind = 'domain' and value ~ '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$')
                or (kind = 'brand' and value ~ '^[a-z0-9][a-z0-9-]*$')
            )
        SQL);

        DB::statement('alter table free_mail_domains drop constraint free_mail_domains_kind_check');
        DB::statement("alter table free_mail_domains add constraint free_mail_domains_kind_check check (kind in ('domain', 'brand'))");
    }
};
