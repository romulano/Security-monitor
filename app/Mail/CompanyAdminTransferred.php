<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A administração da empresa passou de uma conta para outra (CompanyRoles): o aviso à nova e o registro à antiga. */
class CompanyAdminTransferred extends Mailable
{
    public function __construct(public string $name, public string $company, public string $formerAdmin, public string $newAdmin, public bool $isNew) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isNew
            ? "Você agora administra a conta de {$this->company} no Security Monitor"
            : "A administração de {$this->company} no Security Monitor passou para {$this->newAdmin}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-admin-transferred', text: 'emails.company-admin-transferred-text');
    }
}
