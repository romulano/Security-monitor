<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * O /.well-known/security.txt do Security Monitor (RFC 9116): a quem avisar de uma falha
 * no Security Monitor e de tráfego vindo dele. Os contatos vêm da configuração.
 *
 * O Expires anda sozinho: 180 dias à frente, à meia-noite UTC (o texto só muda
 * uma vez por dia). Um Expires fixo no arquivo venceria em silêncio se ninguém
 * lembrasse de renovar, e quem lê um security.txt vencido o descarta. Os
 * contatos são revistos junto com o código, que é o que o Expires protege.
 */
class SecurityTxt
{
    public const VALID_DAYS = 180;

    public function render(Carbon $now): string
    {
        $security = (string) config('secmon.security_txt.security');
        $abuse = (string) config('secmon.security_txt.abuse');

        return implode("\n", [
            '# security.txt do Security Monitor (RFC 9116). Respondemos em português ou inglês.',
            "# Achou uma falha de segurança no Security Monitor? Escreva para {$security}.",
            "# Recebeu tráfego vindo do Security Monitor e quer denunciar ou pedir que pare? Escreva para {$abuse}.",
            "Contact: mailto:{$security}",
            "Contact: mailto:{$abuse}",
            'Expires: '.$now->copy()->utc()->startOfDay()->addDays(self::VALID_DAYS)->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: '.config('secmon.security_txt.languages'),
            'Canonical: '.route('security-txt'),
        ])."\n";
    }
}
