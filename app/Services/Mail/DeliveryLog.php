<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lê o log de entrega do servidor de envio (o volume mail_log, só leitura no app)
 * e atualiza cada e-mail pelo id da fila do Postfix: "sent" é entregue, "deferred"
 * é adiado (o Postfix tenta de novo por até 1 dia), "bounced" e "expired" são
 * devolvidos, sempre com a resposta do servidor do destinatário.
 *
 * Roda a cada minuto (secmon:mail-status) e continua de onde parou: o cursor (inode e
 * posição) fica em Settings. Log rodado (inode novo ou arquivo menor) recomeça do zero.
 */
class DeliveryLog
{
    private const CURSOR = 'mail.log_cursor';

    /** Quando o leitor passou pela última vez, com ou sem novidade (o cursor só muda com novidade). */
    private const READ_AT = 'secmon.mail.log_read_at';

    /** No máximo isso por rodada; o resto fica para o próximo minuto. */
    private const MAX_BYTES = 2_000_000;

    private const DELIVERY = '/postfix\/[\w-]+\[\d+\]: ([0-9A-Za-z]+): to=<[^>]*>,.*? dsn=([0-9.]+), status=(sent|deferred|bounced) \((.*)\)\s*$/';

    private const EXPIRED = '/postfix\/[\w-]+\[\d+\]: ([0-9A-Za-z]+): from=<[^>]*>, status=expired, (.*)$/';

    private const STATUS = ['sent' => 'entregue', 'deferred' => 'adiado', 'bounced' => 'devolvido'];

    /** @return int linhas de entrega aplicadas */
    public function sync(): int
    {
        $path = (string) config('secmon.mail.log_path');

        if ($path === '' || ! is_readable($path)) {
            return 0;
        }

        Cache::put(self::READ_AT, now()->toIso8601String(), now()->addDay());
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $inode = (int) fileinode($path);
        $cursor = Settings::get(self::CURSOR, []);
        $offset = is_array($cursor) && ($cursor['inode'] ?? null) === $inode && ($cursor['offset'] ?? 0) <= $size
            ? (int) $cursor['offset']
            : 0;

        if ($offset >= $size) {
            return 0;
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $offset);
        $chunk = (string) fread($handle, self::MAX_BYTES);
        fclose($handle);

        // Só linhas inteiras: a última, ainda sendo escrita, fica para a próxima vez.
        $end = strrpos($chunk, "\n");

        if ($end === false) {
            return 0;
        }

        $applied = 0;

        foreach (explode("\n", substr($chunk, 0, $end)) as $line) {
            $applied += $this->apply($line);
        }

        Settings::set(self::CURSOR, ['inode' => $inode, 'offset' => $offset + $end + 1]);

        return $applied;
    }

    /** Quando o leitor passou pelo log pela última vez (Admin → E-mails mostra se ele parou). */
    public function lastRun(): ?string
    {
        return Cache::get(self::READ_AT);
    }

    private function apply(string $line): int
    {
        if (preg_match(self::DELIVERY, $line, $m)) {
            [, $queueId, $dsn, $status, $response] = $m;
            $status = self::STATUS[$status];
        } elseif (preg_match(self::EXPIRED, $line, $m)) {
            [, $queueId, $response] = $m;
            [$dsn, $status] = ['', 'devolvido'];
        } else {
            return 0;
        }

        $changes = ['status' => $status, 'last_response' => Str::limit(trim(($dsn ? "{$dsn} " : '').$response), 1000)];

        if ($status === 'entregue') {
            $changes['delivered_at'] = now();
        } elseif ($status === 'devolvido') {
            $changes['failed_at'] = now();
        }

        // Entregue e devolvido são finais: uma linha atrasada de "adiado" não volta a mensagem atrás.
        return EmailMessage::query()
            ->where('queue_id', $queueId)
            ->whereNotIn('status', ['entregue', 'devolvido'])
            ->update($changes) > 0 ? 1 : 0;
    }
}
