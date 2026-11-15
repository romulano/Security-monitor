<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertMatch;
use App\Services\Alerts\AlertFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Os links da mensagem do alerta (/a/…) são para a pessoa: a prévia do Slack não monta cartão nem vota, e cada voto vale uma vez. */
class AlertLinksTest extends TestCase
{
    use AlertsTestHelpers, RefreshDatabase;

    private const SLACK = 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)';

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    private function match(): AlertMatch
    {
        $case = $this->newsCase('Nginx RCE');

        return AlertMatch::query()->create([
            'profile_id' => $this->profile()->id, 'case_id' => $case->id, 'kind' => 'new_case', 'criticity' => 'high', 'score' => 60,
            'reasons' => [], 'delivery_status' => 'sent', 'matched_at' => now(),
        ]);
    }

    public function test_link_previews_do_not_vote(): void
    {
        $match = $this->match();
        $relevant = URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDay(), ['match' => $match->id]);
        $irrelevant = URL::temporarySignedRoute('alerts.feedback.signed', now()->addDay(), ['match' => $match->id]);

        foreach ([self::SLACK, 'Slack-ImgProxy (+https://api.slack.com/robots)', 'Mozilla/5.0 (compatible; Discordbot/2.0)', ''] as $robot) {
            $this->withHeader('User-Agent', $robot)->get($relevant)->assertNotFound();
            $this->withHeader('User-Agent', $robot)->get($irrelevant)->assertNotFound();
        }

        // Quem só confere o link (HEAD) também não vota.
        $this->withHeader('User-Agent', self::BROWSER)->call('HEAD', $relevant)->assertNotFound();

        $this->assertNull($match->fresh()->feedback);

        $this->withHeader('User-Agent', self::BROWSER)->get($irrelevant)->assertOk();
        $this->assertSame('irrelevant', $match->fresh()->feedback);
    }

    public function test_the_first_vote_counts_and_then_both_links_only_show_it(): void
    {
        $match = $this->match();
        $relevant = URL::temporarySignedRoute('alerts.feedback.signed.relevant', now()->addDays(30), ['match' => $match->id]);
        $irrelevant = URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id]);

        $this->get($relevant)->assertOk()->assertSee('Pronto, marcamos como relevante.')->assertSee('Salvar motivo');
        $votedAt = $match->refresh()->feedback_at;
        $this->travel(5)->minutes();

        // De novo no mesmo link e no oposto: 200 com a resposta registrada, sem gravar nada.
        foreach ([$relevant, $irrelevant] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Esta resposta já estava registrada: relevante.')
                ->assertSee('Os links da mensagem valem um clique: nada mudou agora.')
                ->assertDontSee('Pronto, marcamos');
        }
        $this->assertSame('relevant', $match->refresh()->feedback);
        $this->assertTrue($votedAt->equalTo($match->feedback_at));

        // No painel, com login, mudar continua livre; o link segue mostrando o que está gravado.
        $this->actingAs($match->profile->user)->post(route('alerts.feedback', $match), ['feedback' => 'irrelevant'])->assertSessionHas('status');
        $this->get($relevant)->assertOk()->assertSee('Esta resposta já estava registrada: não relevante.');
        $this->assertSame('irrelevant', $match->refresh()->feedback);
    }

    public function test_the_note_of_the_signed_page_is_sent_once_and_keeps_the_vote(): void
    {
        $match = $this->match();
        $this->get(URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id]))->assertOk();
        $note = URL::temporarySignedRoute('alerts.feedback.signed.note', now()->addDays(30), ['match' => $match->id]);

        // A outra opção vem desligada, e o servidor também não troca o voto por aqui.
        $page = $this->get(URL::temporarySignedRoute('alerts.feedback.signed', now()->addDays(30), ['match' => $match->id]))->getContent();
        $this->assertMatchesRegularExpression('/value="relevant"[^>]*disabled/', $page);
        $this->assertDoesNotMatchRegularExpression('/value="irrelevant"[^>]*disabled/', $page);
        $this->post($note, ['feedback' => 'relevant', 'note' => 'Usamos Nginx.'])->assertOk()
            ->assertSee('A resposta já está registrada como “não relevante”. Para trocá-la, entre no Security Monitor, na aba Notícias.');
        $this->assertSame(['irrelevant', null], [$match->refresh()->feedback, $match->feedback_note]);

        $this->post($note, ['feedback' => 'irrelevant', 'note' => 'Não usamos Nginx.'])->assertOk()
            ->assertSee('Motivo salvo.')->assertSee('O motivo desta página já foi enviado.')->assertDontSee('Salvar motivo');

        $this->post($note, ['feedback' => 'irrelevant', 'note' => 'Outro motivo.'])->assertOk()
            ->assertSee('O motivo deste alerta já foi registrado por este link.')->assertDontSee('Salvar motivo');
        $this->assertSame('Não usamos Nginx.', $match->refresh()->feedback_note);
    }

    public function test_two_clicks_at_once_record_one_vote(): void
    {
        $match = $this->match();
        $feedback = app(AlertFeedback::class);

        // O segundo pedido espera a trava do primeiro e já encontra o voto.
        $this->assertTrue($feedback->vote(AlertMatch::query()->find($match->id), 'relevant'));
        $this->assertFalse($feedback->vote(AlertMatch::query()->find($match->id), 'irrelevant'));
        $this->assertSame('relevant', $match->refresh()->feedback);
    }

    public function test_read_link_leads_people_to_the_story_and_previews_nowhere(): void
    {
        $uuid = $this->match()->case->uuid;

        $this->withHeader('User-Agent', self::BROWSER)->get(route('alerts.read', $uuid))
            ->assertRedirect(route('feed.show', $uuid));
        $this->withHeader('User-Agent', self::SLACK)->get(route('alerts.read', $uuid))->assertNotFound();
        $this->withHeader('User-Agent', self::BROWSER)->get('/a/materia/nao-e-uuid')->assertNotFound();
    }
}
