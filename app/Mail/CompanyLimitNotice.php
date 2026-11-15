<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Um limite da empresa chegou a 80% ou a 100% (E3d, DESENHO-5.3 §4.2; E5d). `$what` é o que conta ("domínios",
 * "subdomínios de x.com.br", "contas"…) e `$limit` a chave do limite, para dizer o que fazer e o nome do botão. Quem
 * recebe é quem administra (`$sector` nulo) ou quem responde pelo setor `$sector` (Designer, rodada 3).
 */
class CompanyLimitNotice extends Mailable
{
    /** O que fazer no limite, por limite. */
    public const ACTIONS = [
        'accounts' => 'Para convidar outra pessoa, remova uma conta ou cancele um convite pendente.',
        'alert_profiles' => 'Para criar outro, exclua um alerta que não use mais.',
        'github_monitors' => 'Para criar outro, exclua um monitoramento.',
        'general_channels' => 'Para conectar outro, desconecte um em Notificadores.',
        'sector_channels' => 'Para conectar outro, desconecte um em Notificadores.',
        'webhooks' => 'Para conectar outro, desconecte um em Notificadores.',
        'domains' => 'Para cadastrar outro, remova um domínio.',
        'subdomains' => 'Para cadastrar outro, tire um subdomínio da lista.',
        'radar_seeds' => 'Os que passarem disso ficam fora do Radar até sobrar vaga.',
    ];

    /** O botão, com o nome da tela. */
    public const BUTTONS = [
        'accounts' => 'Ver as contas da empresa',
        'alert_profiles' => 'Ver os alertas de Notícias',
        'github_monitors' => 'Ver os monitoramentos do GitHub',
        'general_channels' => 'Ver os Notificadores',
        'sector_channels' => 'Ver os Notificadores',
        'webhooks' => 'Ver os Notificadores',
    ];

    public function __construct(
        public string $name,
        public string $company,
        public string $what,
        public int $used,
        public int $max,
        public int $level,
        public string $url,
        public string $limit = 'domains',
        public ?string $sector = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->level >= 100
            ? "Security Monitor: {$this->company} chegou ao limite de {$this->what}"
            : "Security Monitor: {$this->company} usou {$this->used} de {$this->max} {$this->what}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.company-limit', text: 'emails.company-limit-text', with: [
            'action' => self::ACTIONS[$this->limit] ?? 'Para cadastrar outro, é preciso remover algum.',
            'button' => self::BUTTONS[$this->limit] ?? 'Ver os domínios da empresa',
            'note' => $this->limit === 'accounts' ? ' (os convites pendentes contam)' : '',
            'more' => $this->sector === null ? 'Se precisar de mais, fale com o Security Monitor pelo contato@example.com.' : 'Se precisar de mais, fale com quem administra a empresa.',
            'why' => $this->sector === null
                ? 'Você recebe este aviso porque administra a conta da empresa no Security Monitor.'
                : "Você recebe este aviso porque responde pelo setor {$this->sector} na conta da empresa.",
        ]);
    }
}
