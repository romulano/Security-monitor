<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os domínios de e-mail genérico (webmail grátis) que a carga de vazamentos descarta: só credencial
 * de empresa é guardada. A lista é do admin — ele cadastra e remove em Admin → Vazamentos, sem
 * depender de deploy, porque webmail novo aparece toda hora.
 *
 * Duas naturezas na mesma tabela:
 *
 * - `domain`: o domínio exato (`gmail.com`, `uol.com.br`).
 * - `brand`: a marca, casada como `marca.<sufixo público>`. É o que pega `hotmail.co.uk`,
 *   `live.com.br` e `outlook.fr` sem cadastrar os 127 domínios da Microsoft um por um. O sufixo é
 *   conferido contra a lista de TLDs do parser, então `live.minhaempresa.com` NÃO é pego — e
 *   continua valendo como empresa.
 *
 * A semente é a lista curada que estava no código. Para cobertura total existem as listas públicas
 * (~96 mil domínios); elas entram pelo `leaks:freemail-import`, não por aqui, porque uma migration
 * que insere 96 mil linhas trava o deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('free_mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 6)->default('domain');
            $table->string('value', 253);
            $table->string('note', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("alter table free_mail_domains add constraint free_mail_domains_kind_check check (kind in ('domain', 'brand'))");
        DB::statement("alter table free_mail_domains add constraint free_mail_domains_value_check check (value = lower(value) and value = btrim(value) and value <> '')");
        // `domain` tem ponto e TLD; `brand` é um rótulo só, sem ponto (`hotmail`, não `hotmail.com`).
        DB::statement(<<<'SQL'
            alter table free_mail_domains add constraint free_mail_domains_shape_check check (
                (kind = 'domain' and value ~ '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$')
                or (kind = 'brand' and value ~ '^[a-z0-9][a-z0-9-]*$')
            )
        SQL);
        DB::statement('create unique index free_mail_domains_kind_value on free_mail_domains (kind, value)');
        DB::statement('create index free_mail_domains_created_by on free_mail_domains (created_by)');

        $now = now();
        $rows = [];

        foreach (self::DOMAINS as $value) {
            $rows[] = ['kind' => 'domain', 'value' => $value, 'note' => null, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (self::BRANDS as $value) {
            $rows[] = ['kind' => 'brand', 'value' => $value, 'note' => 'pega os domínios de país da marca', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('free_mail_domains')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('free_mail_domains');
    }

    private const DOMAINS = [
        'gmail.com', 'googlemail.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me',
        'protonmail.com', 'protonmail.ch', 'pm.me', 'tutanota.com', 'tutanota.de', 'tuta.io',
        'fastmail.com', 'hushmail.com', 'zoho.com', 'zohomail.com', 'mail.com', 'email.com',
        'usa.com', 'consultant.com', 'inbox.com', 'yandex.com', 'yandex.ru', 'ya.ru',
        'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'internet.ru', 'rambler.ru', 'lenta.ru',
        'web.de', 'freenet.de', 't-online.de', 'arcor.de', 'gmx.de', 'gmx.net', 'gmx.com',
        'orange.fr', 'wanadoo.fr', 'laposte.net', 'sfr.fr', 'free.fr', 'neuf.fr', 'club-internet.fr',
        'libero.it', 'virgilio.it', 'tiscali.it', 'alice.it', 'tin.it',
        'uol.com.br', 'bol.com.br', 'terra.com.br', 'ig.com.br', 'globo.com', 'globomail.com',
        'r7.com', 'oi.com.br', 'zipmail.com.br', 'superig.com.br',
        'qq.com', 'foxmail.com', '163.com', '126.com', 'yeah.net', 'sina.com', 'sina.cn',
        'sohu.com', 'aliyun.com', '21cn.com', 'naver.com', 'daum.net', 'hanmail.net', 'nate.com',
        'rediffmail.com', 'sify.com', 'indiatimes.com',
        'onet.pl', 'wp.pl', 'interia.pl', 'o2.pl', 'op.pl',
        'seznam.cz', 'abv.bg', 'mynet.com', 'walla.com', 'walla.co.il',
        'nifty.com', 'docomo.ne.jp', 'softbank.ne.jp', 'ezweb.ne.jp', 'au.com',
        'telefonica.net', 'prodigy.net.mx', 'fibertel.com.ar', 'speedy.com.ar',
        'aol.com', 'aim.com', 'love.com', 'games.com', 'cs.com', 'netscape.net',
        'gmx.at', 'gmx.ch', 'bluewin.ch', 'chello.nl', 'ziggo.nl', 'home.nl', 'planet.nl',
    ];

    private const BRANDS = [
        'hotmail', 'outlook', 'live', 'msn', 'windowslive', 'passport',
        'yahoo', 'ymail', 'rocketmail', 'yahoogroups',
        'gmx', 'aol', 'mail', 'email', 'inbox', 'hotmai', 'yandex',
    ];
};
