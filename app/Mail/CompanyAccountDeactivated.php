<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Para a conta removida da empresa: ficou desativada e é apagada em 30 dias (CompanyRemoval). */
class CompanyAccountDeactivated extends Mailable
{
    public function __construct(public string $name, public string $company, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Sua conta de {$this->company} no Security Monitor foi removida");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-account-deactivated', text: 'emails.company-account-deactivated-text');
    }
}
