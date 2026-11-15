<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail de teste do Admin → E-mails: confere servidor de envio, SPF e DKIM de ponta a ponta. */
class TestEmail extends Mailable
{
    public function __construct(public string $requestedAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Security Monitor: teste de envio');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.test', text: 'emails.test-text');
    }
}
