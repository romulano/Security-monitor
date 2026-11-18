<?php

namespace Tests\Feature;

use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Services\Leaks\LeakImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** A carga de um dump da pasta de vazamentos: guarda empresa, descarta genérico, põe o duvidoso na fila do humano. */
class LeakImportTest extends TestCase
{
    use RefreshDatabase;

    /** Linhas de verdade do formato de stealer, nas três ordens que o arquivo traz. */
    private const DUMP = [
        'PE https://login.live.com/oauth20_authorize.srf:ana@exemplocorp.com:Senha@000111',
        'FR https://suporte.exemplo.com/acesso/:usuario01:Senha-Teste-0001',
        'ID https://player.exemplo.com/login/:alguem@gmail.com:Cidade123',
        'fulano8600000000@corp.com.br:Fulano123:https://app.exemplo.com/',
        'IN https://www.exemplo88.net/:Usuaria999:CIDADE2026',
        'Usuaria999:CIDADE2026:https://www.exemplo88.net/',
        '',
        'https://a.com/x:https://b.com/y:z',
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

        $this->assertSame(7, $tally['linhas'], 'a linha em branco não conta');
        $this->assertSame(1, $tally['vazias']);
        $this->assertSame(2, $tally['salvas'], 'exemplocorp.com e corp.com.br');
        $this->assertSame(1, $tally['genericas'], 'gmail.com');
        $this->assertSame(2, $tally['sem_email'], 'usuario01 e Usuaria999 não são e-mail');
        $this->assertSame(2, $tally['humano'], 'ordem ambígua e dois endereços');

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

    public function test_what_it_cannot_prove_waits_for_a_human(): void
    {
        app(LeakImporter::class)->import('Amostra2026', $this->dump(), 'leaks');

        $lines = LeakImportLine::query()->orderBy('line_no')->get();

        $this->assertCount(2, $lines);
        $this->assertSame(['ordem_ambigua', 'dois_enderecos'], $lines->pluck('reason')->all());
        $this->assertSame([6, 8], $lines->pluck('line_no')->all(), 'a linha guarda onde estava no arquivo');
        $this->assertTrue($lines->every(fn ($line) => $line->status === 'pending'));
        // Os campos já partidos poupam o humano de recortar a linha à mão na tela.
        $this->assertSame(['Usuaria999', 'CIDADE2026', 'https://www.exemplo88.net/'], $lines->first()->fields);
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
        $this->assertSame(2, LeakImportLine::count());
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

    public function test_a_missing_dump_fails_loudly(): void
    {
        Storage::fake('leaks');

        $this->expectException(RuntimeException::class);

        app(LeakImporter::class)->import('Amostra2026', 'dumps/nao-existe.txt', 'leaks');
    }
}
