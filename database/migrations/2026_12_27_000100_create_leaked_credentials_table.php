<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciais vazadas: o palheiro dos dumps públicos, uma linha por par e-mail/senha em cada dump.
 *
 * Ao contrário de `exposed_keys`, esta tabela não se prende ao domínio comprovado: ela guarda o
 * vazamento inteiro, de qualquer domínio, e o cruzamento com a conta é feito na consulta
 * (`join owned_domains o on o.domain = l.domain`). Por isso `domain` é texto, e não uma FK.
 *
 * - `domain`: o host do e-mail (o que vem depois do `@`, subdomínio incluído), repetido na linha de
 *   propósito, para filtrar e indexar sem `split_part` em cima de milhões de linhas. Uma restrição
 *   garante que ele casa com o e-mail.
 * - `email`: sempre em minúsculas, como a carga normaliza.
 * - `password`: a senha em claro, como veio no dump. É o dado do vazamento: sem ela não há como
 *   dizer ao cliente qual senha queimou. Nada a ver com `users.password`, que é hash.
 * - `source`: o nome do dump de onde a linha veio (`Amostra2026`). É o que permite recarregar um
 *   dump só (`delete ... where source = ?`) e dizer ao cliente em qual vazamento a senha apareceu.
 * - `fingerprint`: md5 de `email:password`, a chave de deduplicação da carga.
 * - `leaked_at`: a data do vazamento quando o dump informa; nulo é o caso comum.
 *
 * A chave única é (`source`, `fingerprint`), não só `fingerprint`: o mesmo par aparece em vários
 * dumps, e colapsar tudo numa linha jogaria fora justamente a informação de em quantos vazamentos a
 * senha queimou. Com ela a carga é idempotente por dump, e recarregar um dump não mexe nos outros.
 *
 * Sem `updated_at`: a linha é um fato imutável, e o par de timestamps custaria 16 bytes em cada uma
 * dos milhões de linhas. O model precisa de `const UPDATED_AT = null;`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::create('leaked_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253);
            $table->string('email', 320);
            $table->string('password', 255);
            $table->string('url', 2048)->nullable();
            $table->string('site_host', 253)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('source', 64);
            $table->char('fingerprint', 32);
            $table->timestampTz('leaked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            alter table leaked_credentials
                add constraint leaked_credentials_fingerprint_check check (fingerprint ~ '^[0-9a-f]{32}$')
        SQL);

        // O e-mail chega normalizado e com cara de e-mail; a carga é que valida de verdade.
        DB::statement(<<<'SQL'
            alter table leaked_credentials
                add constraint leaked_credentials_email_check check (email = lower(email) and email like '%@%.%')
        SQL);

        // O domínio é mesmo o do e-mail. A regex também barra `%` e `_`, que viram curinga no like.
        DB::statement(<<<'SQL'
            alter table leaked_credentials
                add constraint leaked_credentials_domain_check check (
                    domain ~ '^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$' and email like ('%@' || domain)
                )
        SQL);

        // Permissivo de propósito: nome de dump tem espaço e `#` ("Collection #1"). Só não aceita
        // vazio nem sobra de espaço, que furariam o `delete ... where source = ?`.
        DB::statement(<<<'SQL'
            alter table leaked_credentials
                add constraint leaked_credentials_source_check check (source = btrim(source) and source <> '')
        SQL);

        DB::statement('create unique index leaked_credentials_source_fingerprint on leaked_credentials (source, fingerprint)');
        DB::statement('create index leaked_credentials_domain_email on leaked_credentials (domain, email)');
        DB::statement('create index leaked_credentials_email on leaked_credentials (email)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leaked_credentials');
    }
};
