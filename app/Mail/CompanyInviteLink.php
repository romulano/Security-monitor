<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** O convite para entrar na conta de uma empresa no Security Monitor (CompanyInvites). */
class CompanyInviteLink extends Mailable
{
    public function __construct(public string $company, public ?string $inviter, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Convite para a conta de {$this->company} no Security Monitor");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-invite', text: 'emails.company-invite-text');
    }
}
