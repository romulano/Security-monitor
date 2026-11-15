<?php

namespace Tests\Feature;

use App\Services\Hermes\Hermes;
use App\Services\Hermes\HermesLimitReached;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** O cliente único do hermes: o que todo serviço de IA usa para falar com a ponte. */
class HermesClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['secmon.enricher.hermes.token' => 'segredo']);
        Settings::flush();
        Settings::setMany([
            'enricher.hermes_url' => 'http://hermes.test:8765/',
            'enricher.hermes_model' => 'sonnet',
        ]);
    }

    private function reply(string $content): void
    {
        Http::fake(['hermes.test:8765/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    public function test_chat_posts_the_conversation_with_token_model_and_timeout(): void
    {
        $this->reply('olá');

        $this->assertSame('olá', Hermes::chat('regras', 'pergunta', 120));

        Http::assertSent(function (Request $request) {
            $this->assertSame('http://hermes.test:8765/v1/chat/completions', $request->url());
            $this->assertSame(['Bearer segredo'], $request->header('Authorization'));
            $this->assertSame([
                'model' => 'sonnet',
                'timeout_seconds' => 120,
                'messages' => [
                    ['role' => 'system', 'content' => 'regras'],
                    ['role' => 'user', 'content' => 'pergunta'],
                ],
            ], $request->data());

            return true;
        });
    }

    public function test_chat_leaves_out_the_authorization_header_without_a_token_and_the_model_when_empty(): void
    {
        config(['secmon.enricher.hermes.token' => '']);
        Settings::setMany(['enricher.hermes_model' => '']);
        $this->reply('ok');

        Hermes::chat('s', 'u', 30);

        Http::assertSent(function (Request $request) {
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertArrayNotHasKey('model', $request->data());

            return true;
        });
    }

    public function test_chat_passes_extra_fields_and_drops_the_empty_ones(): void
    {
        $this->reply('ok');

        Hermes::chat('s', 'u', 30, extra: ['reasoning_effort' => 'low']);
        Hermes::chat('s', 'u', 30, extra: ['reasoning_effort' => '']);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();

        $this->assertSame('low', $sent[0]['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', $sent[1]);
    }

    public function test_chat_accepts_a_message_made_of_text_and_image_parts(): void
    {
        $this->reply('ok');
        $parts = [
            ['type' => 'text', 'text' => 'veja'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']],
        ];

        Hermes::chat('s', $parts, 30);

        Http::assertSent(fn (Request $request) => $request->data()['messages'][1]['content'] === $parts);
    }

    public function test_a_http_error_carries_the_status_and_the_message_of_the_bridge(): void
    {
        Http::fake(['hermes.test:8765/*' => Http::response(['error' => ['message' => 'claude saiu com código 1']], 502)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hermes HTTP 502: claude saiu com código 1');

        Hermes::chat('s', 'u', 30);
    }

    public function test_the_usage_limit_of_the_ai_says_when_it_comes_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 03:00:15', 'UTC'));
        Http::fake(['hermes.test:8765/*' => Http::response(['error' => ['message' => "You've hit your session limit · resets 5am (UTC)"]], 502)]);

        try {
            Hermes::chat('s', 'u', 30);
            $this->fail('devia ter lançado');
        } catch (HermesLimitReached $e) {
            $this->assertSame("hermes HTTP 502: You've hit your session limit · resets 5am (UTC)", $e->getMessage());
            $this->assertSame('2026-10-25 05:00:00', $e->resetsAt->format('Y-m-d H:i:s'));
        }
    }

    public function test_the_reset_hour_already_gone_today_is_tomorrow_and_an_unreadable_one_is_an_hour_away(): void
    {
        $now = Carbon::parse('2026-10-29 13:26:00', 'UTC');

        $this->assertSame('2026-10-30 05:00', HermesLimitReached::from(502, "You've hit your session limit · resets 5am (UTC)", $now)->resetsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-10-29 17:30', HermesLimitReached::from(502, "You've hit your session limit · resets 5:30pm (UTC)", $now)->resetsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 09:00', HermesLimitReached::from(429, "You've hit your weekly limit · resets Nov 3, 9am (UTC)", $now)->resetsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-10-29 14:26', HermesLimitReached::from(429, 'usage limit reached', $now)->resetsAt->format('Y-m-d H:i'));
        $this->assertNull(HermesLimitReached::from(502, 'claude saiu com código 1', $now));
    }

    public function test_a_http_error_without_a_message_falls_back_to_the_start_of_the_body(): void
    {
        Http::fake(['hermes.test:8765/*' => Http::response(str_repeat('x', 500), 500)]);

        try {
            Hermes::chat('s', 'u', 30);
            $this->fail('devia ter lançado');
        } catch (RuntimeException $e) {
            $this->assertSame('hermes HTTP 500: '.str_repeat('x', 300), $e->getMessage());
        }
    }

    public function test_a_reply_without_text_is_an_error(): void
    {
        $this->reply("  \n ");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resposta do hermes sem conteúdo utilizável.');

        Hermes::chat('s', 'u', 30);
    }

    public function test_json_reads_the_object_inside_prose_and_code_fences(): void
    {
        $this->reply("Claro:\n```json\n{\"a\": 1, \"b\": \"dois\"}\n```\nPronto.");

        $this->assertSame(['a' => 1, 'b' => 'dois'], Hermes::json('s', 'u'));
    }

    public function test_json_gives_the_bridge_a_thirty_second_margin_over_the_timeout(): void
    {
        $this->reply('{"a": 1}');

        Hermes::json('s', 'u', 100);

        Http::assertSent(fn (Request $request) => $request->data()['timeout_seconds'] === 100);
    }

    public function test_json_refuses_when_the_bridge_is_not_configured(): void
    {
        Settings::setMany(['enricher.hermes_url' => '']);
        Http::fake();

        $this->assertFalse(Hermes::configured());
        $this->expectExceptionMessage('o hermes não está configurado');

        try {
            Hermes::json('s', 'u');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_decode_and_object_tell_a_usable_reply_from_one_without_json(): void
    {
        $this->assertSame(['x' => 'a b'], Hermes::object("{\"x\": \"a\x0Bb\"}"));
        $this->assertNull(Hermes::object('sem json nenhum'));
        $this->assertNull(Hermes::object('} antes {'));

        $this->expectExceptionMessage('a IA não devolveu JSON utilizável');
        Hermes::decode('sem json nenhum');
    }

    public function test_the_address_comes_without_the_trailing_slash(): void
    {
        $this->assertSame('http://hermes.test:8765', Hermes::url());
        $this->assertTrue(Hermes::configured());
    }

    public function test_sentence_keeps_one_plain_line_up_to_the_limit(): void
    {
        $this->assertSame('Um dois três', Hermes::sentence("  <b>Um</b>\n dois\ttrês  "));
        $this->assertSame('abc', Hermes::sentence('abcdef', 3));
    }
}
