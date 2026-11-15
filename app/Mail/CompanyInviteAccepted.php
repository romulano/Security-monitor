<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Para quem administra a empresa: alguém aceitou o convite e entrou (CompanyInvites). */
class CompanyInviteAccepted extends Mailable
{
    public function __construct(public string $name, public string $company, public string $member, public string $memberEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->member} entrou na conta de {$this->company} no Security Monitor");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-invite-accepted', text: 'emails.company-invite-accepted-text');
    }
}
