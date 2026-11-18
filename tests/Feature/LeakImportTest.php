<?php

namespace Tests\Feature;

use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Services\Leaks\LeakImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** A carga de um dump da pasta de vazamentos: guarda empresa, descarta o resto, e só incomoda o humano quando ele resolve. */
class LeakImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Linhas de verdade do formato de stealer. O comentário diz para onde cada uma deve ir — e o
     * número é a linha no arquivo, que a quarentena guarda.
     */
    private const DUMP = [
        'PE https://login.live.com/oauth20_authorize.srf:ana@exemplocorp.com:Senha@000111', // 1 salva
        'FR https://suporte.exemplo.com/acesso/:usuario01:Senha-Teste-0001',                     // 2 login não é e-mail
        'ID https://player.exemplo.com/login/:alguem@gmail.com:Cidade123',                     // 3 genérico
        'fulano8600000000@corp.com.br:Fulano123:https://app.exemplo.com/',                      // 4 salva
        'IN https://www.exemplo88.net/:Usuaria999:CIDADE2026',                                       // 5 login não é e-mail
        'Usuaria999:CIDADE2026:https://www.exemplo88.net/',                                          // 6 ambígua SEM e-mail
        '',                                                                                       // 7 em branco
        'https://a.com/x:https://b.com/y:z',                                                     // 8 ilegível SEM e-mail
        'https://c.com/x:https://d.com/y:ana@acme.com.br',                                       // 9 ilegível COM e-mail de empresa
        'https://e.com/x:https://f.com/y:zeca@gmail.com',                                        // 10 ilegível, só genérico
    ];

    private function dump(string $path = 'dumps/amostra2026.txt'): string
    {
        Storage::fake('leaks');
        Storage::disk('leaks')->put($path, implode("\n", self::DUMP)."\n");

        return $path;
    }

    public function test_it_keeps_only_company_credentials(): void
    {
        $tally = app(LeakImporter::class)->import('Amostra2026', $this->dump(), 'leaks', '2026-11-18');

        $this->assertSame(9, $tally['linhas'], 'a linha em branco não conta');
        $this->assertSame(1, $tally['vazias']);
        $this->assertSame(2, $tally['salvas'], 'exemplocorp.com e corp.com.br');
        $this->assertSame(2, $tally['genericas'], 'o gmail legível e o gmail da linha ilegível');
        $this->assertSame(4, $tally['sem_email'], 'os dois logins que não são e-mail e as duas ilegíveis sem e-mail');
        $this->assertSame(1, $tally['humano'], 'só a ilegível que tem e-mail de empresa');

        $this->assertSame(
            ['corp.com.br', 'exemplocorp.com'],
            LeakedCredential::query()->orderBy('domain')->pluck('domain')->all(),
        );

        $credential = LeakedCredential::where('domain', 'exemplocorp.com')->sole();
        $this->assertSame('Senha@000111', $credential->password);
        $this->assertSame('login.live.com', $credential->site_host);
        $this->assertSame('PE', $credential->country);
        $this->assertSame('Amostra2026', $credential->source);
        $this->assertSame('2026-11-18', $credential->leaked_at->toDateString());
    }

    /**
     * O ponto do pedido do dono: a fila é do que ele RESOLVE. Linha sem e-mail de empresa não tem
     * decisão possível — a tabela exige e-mail e genérico é descartado —, então não entra na fila.
     */
    public function test_only_what_a_human_could_resolve_goes_to_the_queue(): void
    {
        app(LeakImporter::class)->import('Amostra2026', $this->dump(), 'leaks');

        $lines = LeakImportLine::query()->orderBy('line_no')->get();

        $this->assertCount(1, $lines, 'três linhas eram ilegíveis, mas só uma tem e-mail de empresa');
        $this->assertSame(9, $lines->first()->line_no, 'a linha guarda onde estava no arquivo');
        $this->assertSame('dois_enderecos', $lines->first()->reason);
        $this->assertSame('pending', $lines->first()->status);
        // Os campos já partidos poupam o humano de recortar a linha à mão na tela.
        $this->assertSame(['https://c.com/x', 'https://d.com/y', 'ana@acme.com.br'], $lines->first()->fields);
    }

    /** As duas que o dono achou na tela: sem e-mail nenhum, e com e-mail só genérico. */
    public function test_the_two_shapes_the_owner_found_never_reach_the_queue(): void
    {
        Storage::fake('leaks');
        Storage::disk('leaks')->put('d.txt', implode("\n", [
            'https://www.painel-exemplo.gr/login.asp:empresa-exemplo.gr:senha2010.-',
            'https://accounts.google.com/v3/signin/challenge/pwd:fulana.exemplo@gmail.com:abc12181945.com',
        ])."\n");

        $tally = app(LeakImporter::class)->import('Amostra2026', 'd.txt', 'leaks');

        $this->assertSame(0, $tally['humano'], 'nenhuma das duas dá trabalho ao admin');
        $this->assertSame(1, $tally['sem_email'], 'a primeira não tem e-mail nenhum');
        $this->assertSame(1, $tally['genericas'], 'a segunda só tem gmail, que é descartado por regra');
        $this->assertSame(0, LeakImportLine::count());
        $this->assertSame(0, LeakedCredential::count());
    }

    public function test_reloading_the_same_dump_adds_nothing(): void
    {
        $path = $this->dump();

        app(LeakImporter::class)->import('Amostra2026', $path, 'leaks');
        $again = app(LeakImporter::class)->import('Amostra2026', $path, 'leaks');

        $this->assertSame(0, $again['salvas']);
        $this->assertSame(2, $again['repetidas']);
        $this->assertSame(0, $again['humano'], 'a linha da fila também não entra duas vezes');
        $this->assertSame(2, LeakedCredential::count());
        $this->assertSame(1, LeakImportLine::count());
    }

    public function test_the_same_pair_in_another_dump_is_a_new_row(): void
    {
        $path = $this->dump();

        app(LeakImporter::class)->import('Amostra2026', $path, 'leaks');
        app(LeakImporter::class)->import('Collection #1', $path, 'leaks');

        $this->assertSame(4, LeakedCredential::count(), 'a senha queimou em dois vazamentos');
        $this->assertSame(
            2,
            LeakedCredential::where('domain', 'exemplocorp.com')->distinct()->count('source'),
        );
    }

    /** A limpeza tira da fila o que a regra antiga deixou entrar — e só isso. */
    public function test_the_prune_command_removes_what_no_human_could_resolve(): void
    {
        $inutil = $this->queued('https://a.com/x:https://b.com/y:z');
        $generica = $this->queued('https://a.com/x:https://b.com/y:zeca@gmail.com');
        $util = $this->queued('https://c.com/x:https://d.com/y:ana@acme.com.br');

        // Sem --aplicar não encosta em nada.
        $this->artisan('leaks:limpar-fila')->assertSuccessful();
        $this->assertSame(3, LeakImportLine::count());

        $this->artisan('leaks:limpar-fila --aplicar')->assertSuccessful();

        $this->assertSame([$util->id], LeakImportLine::query()->pluck('id')->all());
        $this->assertDatabaseMissing('leak_import_lines', ['id' => $inutil->id]);
        $this->assertDatabaseMissing('leak_import_lines', ['id' => $generica->id]);
    }

    private function queued(string $raw): LeakImportLine
    {
        return LeakImportLine::create([
            'source' => 'Amostra2026', 'line_no' => 1, 'raw' => $raw, 'reason' => 'dois_enderecos',
            'fields' => explode(':', $raw), 'fingerprint' => md5($raw), 'status' => 'pending',
        ]);
    }

    public function test_a_missing_dump_fails_loudly(): void
    {
        Storage::fake('leaks');

        $this->expectException(RuntimeException::class);

        app(LeakImporter::class)->import('Amostra2026', 'dumps/nao-existe.txt', 'leaks');
    }
}
