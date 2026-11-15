<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Um e-mail, uma conta, sem diferença de caixa: o índice fecha a corrida entre o cadastro e a criação
 * da empresa (ou o convite), e o cadastro, a troca de e-mail e o GitHub conferem sem caixa (EmailNotTaken).
 *
 * Produção conferida em 08/11/2026 pelo coordenador, só leitura: 0 e-mails repetidos por caixa, então o
 * índice sobe sem erro. A consulta, para conferir de novo se o deploy atrasar:
 * select lower(email), count(*) from users group by 1 having count(*) > 1;
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index users_email_lower_unique on users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_email_lower_unique');
    }
};
