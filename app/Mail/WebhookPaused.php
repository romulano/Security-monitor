<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** O aviso de que o webhook parou depois das falhas seguidas (WebhookHealth): o que houve e como fazer voltar. */
class WebhookPaused extends Mailable
{
    public function __construct(
        public string $name,
        public string $webhook,
        public string $host,
        public int $failures,
        public string $error,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Security Monitor: o {$this->webhook} parou de receber os alertas");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.webhook-paused', text: 'emails.webhook-paused-text');
    }
}
