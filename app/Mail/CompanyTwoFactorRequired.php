<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A empresa passou a exigir a 2FA (CompanyTwoFactor): o aviso à conta que ainda não a ligou. */
class CompanyTwoFactorRequired extends Mailable
{
    public function __construct(public string $name, public string $admin, public string $company, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Security Monitor: {$this->company} passou a exigir a verificação em duas etapas");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-two-factor', text: 'emails.company-two-factor-text');
    }
}
