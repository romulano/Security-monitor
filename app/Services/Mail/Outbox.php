<?php

namespace App\Services\Mail;

use App\Jobs\SendEmail;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * A porta de saída de todo e-mail do Security Monitor: registra a mensagem (Admin → E-mails)
 * e põe na fila. Quem entrega é o job SendEmail, no ritmo que o servidor permite
 * (secmon.mail.per_minute); o que acontece depois no servidor do destinatário vem
 * do log do Postfix (DeliveryLog).
 */
class Outbox
{
    public function send(Mailable $mail, string $to, string $kind, ?User $user = null): EmailMessage
    {
        $message = EmailMessage::query()->create([
            'user_id' => $user?->id,
            'kind' => $kind,
            'to_address' => mb_substr($to, 0, 255),
            'subject' => mb_substr((string) (method_exists($mail, 'envelope') ? $mail->envelope()->subject : $mail->subject), 0, 255),
        ]);

        SendEmail::dispatch($message->id, $mail);

        return $message;
    }
}
