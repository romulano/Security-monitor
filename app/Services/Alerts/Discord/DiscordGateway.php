<?php

namespace App\Services\Alerts\Discord;

use RuntimeException;

/**
 * O Discord exige que um bot se conecte ao gateway (websocket) pelo menos uma
 * vez antes de mandar a primeira mensagem. O Security Monitor só usa a API HTTP, então
 * isto conecta, identifica o bot (sem nenhuma intent: não recebe eventos) e
 * desconecta. Cliente websocket mínimo, só para essa conversa.
 */
class DiscordGateway
{
    private const HOST = 'gateway.discord.gg';

    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    /** Devolve o nome do bot quando o Discord confirma (READY). */
    public function identify(string $token, int $timeout = 15): string
    {
        $context = stream_context_create(['ssl' => ['peer_name' => self::HOST, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client('tls://'.self::HOST.':443', $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $socket) {
            throw new RuntimeException("Sem conexão com o gateway do Discord ({$error}).");
        }

        $this->socket = $socket;
        $this->buffer = '';
        stream_set_timeout($socket, $timeout);
        $deadline = time() + $timeout;

        try {
            $this->handshake();

            $hello = $this->readMessage();

            if (($hello['op'] ?? null) !== 10) {
                throw new RuntimeException('O gateway do Discord não mandou o HELLO.');
            }

            $this->sendText(json_encode(['op' => 2, 'd' => [
                'token' => $token,
                'intents' => 0,
                'properties' => ['os' => 'linux', 'browser' => 'secmon', 'device' => 'secmon'],
            ]]));

            while (time() < $deadline) {
                $event = $this->readMessage();

                if (($event['op'] ?? null) === 0 && ($event['t'] ?? null) === 'READY') {
                    $this->close();

                    return (string) ($event['d']['user']['username'] ?? 'bot');
                }

                if (($event['op'] ?? null) === 9) {
                    throw new RuntimeException('O Discord recusou a sessão do bot (INVALID_SESSION).');
                }

                if (($event['op'] ?? null) === 1) {
                    $this->sendText(json_encode(['op' => 1, 'd' => null]));
                }
            }

            throw new RuntimeException('O Discord não confirmou o bot a tempo.');
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function handshake(): void
    {
        $key = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET /?v=10&encoding=json HTTP/1.1\r\nHost: ".self::HOST."\r\n"
            ."Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n"
            ."User-Agent: DiscordBot (https://example.com, 1.0)\r\n\r\n");

        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            $this->fill();
        }

        $head = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 4);

        if (! preg_match('#^HTTP/1\.1 101#', $head)) {
            throw new RuntimeException('O gateway do Discord recusou a conexão ('.strtok($head, "\r\n").').');
        }
    }

    /** Uma mensagem de texto inteira (juntando fragmentos), já em JSON. */
    private function readMessage(): array
    {
        $message = '';

        while (true) {
            [$fin, $opcode, $data] = $this->readFrame();

            match ($opcode) {
                0x8 => throw new RuntimeException('O Discord fechou a conexão (código '.(strlen($data) >= 2 ? unpack('n', $data)[1] : '?').').'),
                0x9 => $this->sendFrame(0xA, $data),
                0xA => null,
                default => $message .= $data,
            };

            if ($fin && in_array($opcode, [0x0, 0x1], true)) {
                return (array) json_decode($message, true);
            }
        }
    }

    /** @return array{0: bool, 1: int, 2: string} */
    private function readFrame(): array
    {
        $head = $this->read(2);
        $first = ord($head[0]);
        $second = ord($head[1]);
        $length = $second & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->read(2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->read(8))[1];
        }

        $mask = ($second & 0x80) ? $this->read(4) : '';
        $data = $length > 0 ? $this->read($length) : '';

        if ($mask !== '') {
            $data = $this->mask($data, $mask);
        }

        return [(bool) ($first & 0x80), $first & 0x0F, $data];
    }

    private function sendText(string $text): void
    {
        $this->sendFrame(0x1, $text);
    }

    /** Frames do cliente vão sempre mascarados. */
    private function sendFrame(int $opcode, string $data): void
    {
        $length = strlen($data);
        $header = chr(0x80 | $opcode);
        $header .= match (true) {
            $length < 126 => chr(0x80 | $length),
            $length < 65536 => chr(0x80 | 126).pack('n', $length),
            default => chr(0x80 | 127).pack('J', $length),
        };
        $mask = random_bytes(4);

        fwrite($this->socket, $header.$mask.$this->mask($data, $mask));
    }

    private function close(): void
    {
        $this->sendFrame(0x8, pack('n', 1000));
    }

    private function mask(string $data, string $mask): string
    {
        $out = '';

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $out .= $data[$i] ^ $mask[$i % 4];
        }

        return $out;
    }

    private function read(int $bytes): string
    {
        while (strlen($this->buffer) < $bytes) {
            $this->fill();
        }

        $chunk = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $chunk;
    }

    private function fill(): void
    {
        $chunk = fread($this->socket, 8192);

        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($this->socket);

            throw new RuntimeException($meta['timed_out'] ? 'O gateway do Discord parou de responder.' : 'O gateway do Discord fechou a conexão.');
        }

        $this->buffer .= $chunk;
    }
}
