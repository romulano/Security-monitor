<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** O aviso à própria conta de que a 2FA foi ligada, desligada, ganhou códigos novos ou travou por códigos errados (TwoFactor): se não foi ela, sabe na hora. */
class TwoFactorChanged extends Mailable
{
    public const EVENTS = [
        'on' => ['foi ligada', 'A partir de agora, entrar no Security Monitor pede também o código do app autenticador.'],
        'off' => ['foi desligada', 'A sua conta voltou a ser protegida só pela senha, e os códigos de recuperação deixaram de valer.'],
        'codes' => ['ganhou códigos de recuperação novos', 'Os códigos de recuperação anteriores deixaram de valer.'],
        'reset' => ['foi redefinida por quem administra a sua empresa', 'O app autenticador e os códigos de recuperação antigos não valem mais, e você saiu dos aparelhos onde estava conectado. No próximo acesso, configure de novo em Minha conta → Verificação em duas etapas.'],
        'locked' => ['travou depois de códigos errados', 'Alguém acertou a sua senha e errou o código do app várias vezes. Se não foi você, troque a senha agora: a 2FA segurou a entrada, mas a senha já não é segredo.'],
    ];

    public function __construct(public string $name, public string $event, public string $when, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Security Monitor: a verificação em duas etapas '.self::EVENTS[$this->event][0]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.two-factor', text: 'emails.two-factor-text');
    }
}
