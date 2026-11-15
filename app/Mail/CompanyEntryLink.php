<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** O link da admin da empresa para escolher a senha e confirmar o e-mail (CompanyEntry). */
class CompanyEntryLink extends Mailable
{
    public function __construct(public string $name, public string $company, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Sua conta de {$this->company} no Security Monitor");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-entry', text: 'emails.company-entry-text');
    }
}
