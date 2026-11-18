<?php

namespace Tests\Feature;

use App\Jobs\ImportLeakDump;
use App\Models\LeakDump;
use App\Models\LeakedCredential;
use App\Models\User;
use App\Services\Leaks\LeakImporter;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin → Vazamentos → Pasta: o admin informa a subpasta de storage/app/private/vazamentos, manda
 * varrer e decide se o arquivo é apagado.
 */
class AdminLeakFolderTest extends TestCase
{
    use RefreshDatabase;

    private const LINHAS = "https://a.com/x:ana@acme.com.br:Senha1\nhttps://b.com/y:jo@acme.com.br:Senha2\n";

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function folder(array $files = ['vazamentos/amostra2026.txt' => self::LINHAS]): void
    {
        Storage::fake('leaks');

        foreach ($files as $path => $content) {
            Storage::disk('leaks')->put($path, $content);
        }

        Settings::set('leaks.prefix', 'vazamentos');
    }

    // ------------------------------------------------------------------ sem login

    public function test_the_owner_scans_without_logging_in(): void
    {
        // Aqui não existe login: o dono da instalação é autenticado em toda requisição e é admin.
        Bus::fake();
        $this->folder();

        $this->post(route('admin.leaks.folder.scan'))->assertRedirect();

        $this->assertSame(1, LeakDump::count());
        $this->assertTrue(LeakDump::first()->requester->is_admin);
    }

    // ------------------------------------------------------------------ a pasta

    public function test_the_admin_informs_the_folder_and_whether_it_deletes(): void
    {
        $this->actingAs($this->admin())->post(route('admin.leaks.folder.update'), [
            'prefix' => '  /dumps/2026/  ', 'delete_after_import' => '1',
        ])->assertRedirect();

        // A barra do começo e do fim cai fora: ela não muda a pasta e atrapalha a comparação.
        $this->assertSame('dumps/2026', Settings::get('leaks.prefix'));
        $this->assertTrue(Settings::bool('leaks.delete_after_import'));
    }

    public function test_the_folder_can_be_the_root_and_junk_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.leaks.folder.update'), ['prefix' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', Settings::get('leaks.prefix'));

        foreach (['../fora', 'pasta com espaco', 'a//b'] as $junk) {
            $this->actingAs($admin)->post(route('admin.leaks.folder.update'), ['prefix' => $junk])
                ->assertSessionHasErrors('prefix');
        }
    }

    public function test_deleting_is_off_until_the_admin_turns_it_on(): void
    {
        $this->assertFalse(Settings::bool('leaks.delete_after_import'), 'apagar é irreversível: nasce desligado');
    }

    // ------------------------------------------------------------------ a varredura

    public function test_the_admin_asks_for_a_scan_and_the_txt_files_are_queued(): void
    {
        Bus::fake();
        $this->folder([
            'vazamentos/amostra2026.txt' => self::LINHAS,
            'vazamentos/collection1.txt' => self::LINHAS,
            'vazamentos/leiame.pdf' => 'nao e txt',
            'outra/fora.txt' => 'fora da pasta',
        ]);

        $this->actingAs($this->admin())->post(route('admin.leaks.folder.scan'))->assertRedirect();

        $this->assertSame(
            ['vazamentos/amostra2026.txt', 'vazamentos/collection1.txt'],
            LeakDump::query()->orderBy('path')->pluck('path')->all(),
            'só os .txt da pasta escolhida',
        );
        $this->assertSame('amostra2026', LeakDump::where('path', 'vazamentos/amostra2026.txt')->sole()->source);
        Bus::assertDispatchedTimes(ImportLeakDump::class, 2);
    }

    public function test_scanning_twice_does_not_queue_the_same_file_again(): void
    {
        Bus::fake();
        $this->folder();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.leaks.folder.scan'));
        $this->actingAs($admin)->post(route('admin.leaks.folder.scan'));

        $this->assertSame(1, LeakDump::count());
        Bus::assertDispatchedTimes(ImportLeakDump::class, 1);
    }

    public function test_the_tab_says_why_when_the_folder_cannot_be_read(): void
    {
        // A raiz do disco aponta para um ARQUIVO, não para uma pasta: listar falha, e a tela
        // mostra o motivo em vez de uma pasta vazia.
        $file = tempnam(sys_get_temp_dir(), 'vazamentos');
        config(['filesystems.disks.leaks.root' => $file]);
        Storage::forgetDisk('leaks');

        try {
            $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'dumps']))
                ->assertOk()
                ->assertSee('Não consegui ler a pasta')
                ->assertSee('storage/app/private/vazamentos');
        } finally {
            @unlink($file);
            Storage::forgetDisk('leaks');
        }
    }

    public function test_the_tab_lists_what_is_in_the_folder(): void
    {
        $this->folder();

        $this->actingAs($this->admin())->get(route('admin.leaks', ['ver' => 'dumps']))
            ->assertOk()
            ->assertSee('vazamentos/amostra2026.txt')
            ->assertSee('nunca carregado')
            ->assertSee(route('admin.leaks.folder.scan'), false);
    }

    // ------------------------------------------------------------------ apagar ou deixar

    private function runJob(): LeakDump
    {
        $dump = LeakDump::sole();
        (new ImportLeakDump($dump->id))->handle(app(LeakImporter::class));

        return $dump->fresh();
    }

    public function test_the_file_stays_in_the_folder_when_the_admin_did_not_ask_to_delete(): void
    {
        Bus::fake();
        $this->folder();
        Settings::set('leaks.delete_after_import', false);
        $this->actingAs($this->admin())->post(route('admin.leaks.folder.scan'));

        $dump = $this->runJob();

        $this->assertSame('done', $dump->status);
        $this->assertSame(2, $dump->tally['salvas']);
        $this->assertFalse($dump->removed);
        Storage::disk('leaks')->assertExists('vazamentos/amostra2026.txt');
        $this->assertSame(2, LeakedCredential::count());
    }

    public function test_the_file_leaves_the_folder_when_the_admin_asked(): void
    {
        Bus::fake();
        $this->folder();
        Settings::set('leaks.delete_after_import', true);
        $this->actingAs($this->admin())->post(route('admin.leaks.folder.scan'));

        $dump = $this->runJob();

        $this->assertSame('done', $dump->status);
        $this->assertTrue($dump->removed);
        Storage::disk('leaks')->assertMissing('vazamentos/amostra2026.txt');
        $this->assertSame(2, LeakedCredential::count(), 'só apaga depois de gravar');
    }

    public function test_an_empty_file_is_never_deleted_even_with_the_option_on(): void
    {
        Bus::fake();
        $this->folder(['vazamentos/vazio.txt' => "\n\n"]);
        Settings::set('leaks.delete_after_import', true);
        $this->actingAs($this->admin())->post(route('admin.leaks.folder.scan'));

        $dump = $this->runJob();

        $this->assertSame('done', $dump->status);
        $this->assertFalse($dump->removed, 'arquivo sem linha é mais provável erro de formato que dump vazio');
        Storage::disk('leaks')->assertExists('vazamentos/vazio.txt');
    }

    public function test_a_dump_that_cannot_be_read_is_marked_failed(): void
    {
        Bus::fake();
        $this->folder();
        $this->actingAs($this->admin())->post(route('admin.leaks.folder.scan'));
        Storage::disk('leaks')->delete('vazamentos/amostra2026.txt');

        try {
            $this->runJob();
        } catch (\Throwable) {
            // O job relança para a fila contar a tentativa; o estado é o que importa aqui.
        }

        $dump = LeakDump::sole();
        $this->assertSame('failed', $dump->status);
        $this->assertNotNull($dump->error);
        $this->assertNotNull($dump->finished_at);
    }
}
