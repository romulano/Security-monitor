<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diário de bordo da /sobre. Os registros viviam fixos no Blade; agora o admin
 * cria e edita em Admin → Diário de bordo. Os que já estavam na página entram
 * aqui para ela continuar igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->date('logged_on')->index();
            $table->string('title', 120);
            $table->text('text');
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['2026-10-19', 'Lançamento', 'O Security Monitor entra em órbita em example.com e começa a captar as primeiras 36 fontes.'],
            ['2026-10-19', 'Canal direto', 'Alertas personalizados: cada cliente recebe no Slack ou no Discord só o que afeta a sua stack.'],
            ['2026-10-19', 'Novas antenas', 'Alertas e boletins da CISA e a GreyNoise passam a ser captados. São 39 fontes.'],
            ['2026-10-20', 'Nova frequência', 'Os alertas também chegam pelo Telegram, numa conversa privada com o bot ou no grupo do time.'],
            ['2026-10-20', 'Antenas ampliadas', 'Laboratórios como CrowdStrike, Wiz e Citizen Lab, os boletins da Palo Alto, da Fortinet e do GitLab, o CERT francês e o FBI passam a ser captados. São 69 fontes.'],
            ['2026-10-20', 'Radar de exploits', 'Quando a notícia cita um CVE com exploit público no Exploit-DB, a matéria mostra o exploit, o link e uma explicação da IA. Casos antigos são revistos todo dia.'],
            ['2026-10-21', 'Radar de domínios', 'Nomes parecidos com o domínio do cliente, como erro de digitação, “-login” ou “-pix”, passam a ser vigiados. Cada um é aberto no computador e no celular, a IA compara com o site original e o aviso chega com o kit de denúncia.'],
            ['2026-10-21', 'Acoplagem', 'Toda meia-noite, as matérias que contam a mesma história por ângulos diferentes são acopladas numa só, com mais detalhes e as fontes de todas juntas. Os links antigos levam para a nova.'],
            ['2026-10-21', 'Sonda no GitHub', 'Chaves vazadas, código privado publicado e repositórios que imitam a marca do cliente passam a ser procurados no GitHub público, uma vez por dia. Cada achado passa por duas análises da IA antes do aviso, e o valor de uma chave nunca é guardado.'],
        ] as [$day, $title, $text]) {
            DB::table('log_entries')->insert([
                'logged_on' => $day,
                'title' => $title,
                'text' => $text,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
