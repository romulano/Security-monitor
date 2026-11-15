<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * A posse de um domínio da empresa (E3d, DESENHO-5.3 §4.1): `unseen`, o TXT sumiu e a posse cai em `$fallsAt` (P6);
 * `lost`, a posse caiu e o que dependia dela parou (`$stopped`). Só para quem configura o domínio (a admin e quem
 * responde pelo setor dele): o TXT com o token da empresa vai no texto.
 *
 * @param  list<string>  $stopped  "2 Radares", "1 alerta de Notícias"…
 */
class CompanyDomainNotice extends Mailable
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $company,
        public string $domain,
        public string $txt,
        public ?Carbon $since,
        public ?Carbon $fallsAt,
        public array $stopped,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'lost'
            ? "Security Monitor: {$this->domain} perdeu a comprovação de posse"
            : "Security Monitor: o TXT de {$this->domain} sumiu — a posse cai em 7 dias");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-domain', text: 'emails.company-domain-text');
    }
}
