<?php

namespace Tests\Feature;

use App\Models\LogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Diário de bordo: o admin cria, edita e remove os registros da /sobre. */
class AdminLogbookTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function entry(string $day = '2026-10-21', string $title = 'Acoplagem', string $text = 'As matérias viram uma só.'): LogEntry
    {
        return LogEntry::create(['logged_on' => $day, 'title' => $title, 'text' => $text]);
    }

    public function test_the_page_lists_the_entries_newest_first_with_their_forms(): void
    {
        LogEntry::query()->delete();
        $old = $this->entry('2026-10-19', 'Lançamento');
        $new = $this->entry('2026-10-22', 'Sonda no GitHub');

        $this->actingAs($this->admin())->get(route('admin.logbook'))
            ->assertOk()
            ->assertSeeInOrder(['Sonda no GitHub', 'Lançamento'])
            ->assertSee('22.10.2026')
            ->assertSee('value="2026-10-19"', false)
            ->assertSee(route('admin.logbook.update', $old), false)
            ->assertSee(route('admin.logbook.destroy', $new), false)
            ->assertSee(route('admin.logbook.store'), false);
    }

    public function test_the_admin_menu_has_the_logbook(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Diário de bordo')
            ->assertSee(route('admin.logbook'), false);
    }

    public function test_the_admin_adds_an_entry_and_it_shows_up_on_the_about_page(): void
    {
        LogEntry::query()->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.logbook.store'), [
                'logged_on' => '2026-10-23',
                'title' => '  Radar às 01:00  ',
                'text' => 'O Radar de domínios varre todo dia à 01:00.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Registro adicionado ao diário de bordo.');

        $entry = LogEntry::query()->firstOrFail();
        $this->assertSame('2026-10-23', $entry->logged_on->toDateString());
        $this->assertSame('Radar às 01:00', $entry->title);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('23.10.2026')
            ->assertSee('Radar às 01:00')
            ->assertSee('O Radar de domínios varre todo dia à 01:00.');
    }

    public function test_the_admin_edits_an_existing_entry(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin())
            ->put(route('admin.logbook.update', $entry), [
                'logged_on' => '2026-10-22',
                'title' => 'Acoplagem noturna',
                'text' => 'Texto novo.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Registro atualizado.');

        $entry->refresh();
        $this->assertSame('2026-10-22', $entry->logged_on->toDateString());
        $this->assertSame('Acoplagem noturna', $entry->title);
        $this->assertSame('Texto novo.', $entry->text);

        $this->get(route('about'))->assertSee('Acoplagem noturna')->assertDontSee('As matérias viram uma só.');
    }

    public function test_the_admin_removes_an_entry(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin())->delete(route('admin.logbook.destroy', $entry))->assertRedirect();

        $this->assertModelMissing($entry);
    }

    public function test_the_entry_needs_a_date_a_title_and_a_text(): void
    {
        $admin = $this->admin();
        $entry = $this->entry();
        $before = LogEntry::query()->count();

        $this->actingAs($admin)->post(route('admin.logbook.store'), [])
            ->assertSessionHasErrors(['logged_on' => 'Informe a data do registro.', 'title' => 'Informe o título do registro.', 'text' => 'Escreva o que mudou.']);

        $this->actingAs($admin)->post(route('admin.logbook.store'), ['logged_on' => '21/10/2026', 'title' => 'x', 'text' => 'y'])
            ->assertSessionHasErrors(['logged_on' => 'Data inválida.']);

        $this->actingAs($admin)->post(route('admin.logbook.store'), ['logged_on' => '2026-10-23', 'title' => str_repeat('a', 121), 'text' => str_repeat('b', 601)])
            ->assertSessionHasErrors(['title', 'text']);

        $this->actingAs($admin)->put(route('admin.logbook.update', $entry), ['logged_on' => '2026-10-23', 'title' => '', 'text' => 'ok'])
            ->assertSessionHasErrors('title');

        $this->assertSame($before, LogEntry::query()->count());
        $this->assertSame('Acoplagem', $entry->refresh()->title);
    }

    public function test_a_failed_edit_reopens_that_entry_with_what_was_typed(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->admin())->from(route('admin.logbook'))
            ->put(route('admin.logbook.update', $entry), ['_entry' => $entry->id, 'logged_on' => '2026-10-23', 'title' => '', 'text' => 'Texto digitado'])
            ->assertRedirect(route('admin.logbook'));

        $this->get(route('admin.logbook'))->assertOk()->assertSee('Texto digitado');
    }

}
