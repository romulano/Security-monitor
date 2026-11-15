<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** O link que confirma o e-mail da conta (EmailVerification). */
class VerifyEmail extends Mailable
{
    public function __construct(public string $name, public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme o seu e-mail no Security Monitor');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify', text: 'emails.verify-text');
    }
}
