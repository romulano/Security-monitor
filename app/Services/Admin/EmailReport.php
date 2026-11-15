<?php

namespace App\Services\Admin;

use App\Mail\TestEmail;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Alerts\Discovery\DnsResolver;
use App\Services\Mail\DeliveryLog;
use App\Services\Mail\Outbox;
use Illuminate\Support\Carbon;

/**
 * Admin → E-mails: se o envio está de pé (mailer, servidor de envio, DKIM, SPF e
 * DMARC publicados, leitor do log andando), quantos e-mails saíram e quais deram
 * erro, com a resposta do servidor do destinatário, e o botão de teste.
 */
class EmailReport
{
    /** Sem leitura do log há mais que isso (o agendador roda a cada minuto), o leitor parou. */
    private const READER_STALE_MINUTES = 10;

    public function __construct(private DnsResolver $dns, private DeliveryLog $log, private Outbox $outbox) {}

    /** @return array<string, mixed> */
    public function overview(bool $onlyErrors): array
    {
        $query = EmailMessage::query()->with('user:id,name,email')->latest()->latest('id');

        if ($onlyErrors) {
            $query->whereIn('status', EmailMessage::ERRORS);
        }

        return [
            'health' => $this->health(),
            'counts' => [
                '24 h' => $this->counts(now()->subDay()),
                '7 dias' => $this->counts(now()->subDays(7)),
            ],
            'messages' => $query->limit(100)->get(),
            'onlyErrors' => $onlyErrors,
        ];
    }

    public function sendTest(string $to, User $admin): void
    {
        $when = now()->timezone(config('app.display_timezone', 'America/Sao_Paulo'))->format('d/m/Y H:i');

        $this->outbox->send(new TestEmail($when), $to, 'teste', $admin);
    }

    /** @return array<string, int> situação → quantos desde $since */
    private function counts(Carbon $since): array
    {
        $rows = EmailMessage::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(array_keys(EmailMessage::STATUSES))->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s] ?? 0)])->all();
    }

    /** @return array<string, array{ok: bool, label: string, detail: string}> */
    private function health(): array
    {
        $mailer = (string) config('mail.default');
        $smtp = $mailer === 'smtp';
        $domain = (string) config('secmon.mail.domain');

        return [
            'mailer' => $smtp
                ? $this->state(true, 'SMTP', config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').' · de '.config('mail.from.address'))
                : $this->state(false, $mailer, 'o e-mail não sai do servidor: em produção, MAIL_MAILER=smtp, MAIL_HOST=mail e MAIL_PORT=25'),
            'server' => $smtp ? $this->server() : $this->state(false, 'sem SMTP', 'o mailer atual não usa o servidor de envio'),
            'dkim' => $this->dkim(),
            'spf' => $this->spf($domain),
            'dmarc' => $this->dmarc($domain),
            'reader' => $this->reader($smtp),
        ];
    }

    /** O servidor de envio responde na porta (a saudação "220 ... ESMTP"). */
    private function server(): array
    {
        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port');
        $socket = @fsockopen($host, $port, $code, $error, 2);

        if (! $socket) {
            return $this->state(false, 'fora do ar', "{$host}:{$port} não respondeu ({$error})");
        }

        stream_set_timeout($socket, 2);
        $banner = trim((string) fgets($socket, 512));
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return str_starts_with($banner, '220')
            ? $this->state(true, 'no ar', $banner)
            : $this->state(false, 'resposta estranha', $banner ?: 'sem saudação');
    }

    /** A chave que o servidor gerou (no volume) contra a que está publicada no DNS. */
    private function dkim(): array
    {
        $value = $this->file((string) config('secmon.mail.dkim_path'));
        $name = $this->file((string) config('secmon.mail.dkim_name_path'));

        if (! $value || ! $name) {
            return $this->state(false, 'sem chave', 'o servidor de envio ainda não gerou a chave (sobe no primeiro boot do container mail)') + ['record' => null];
        }

        $record = ['name' => $name, 'value' => $value];
        $published = collect($this->dns->txt($name))->first(fn ($txt) => str_contains($txt, 'v=DKIM1'));

        if (! $published) {
            return $this->state(false, 'não publicado', "cadastre o TXT {$name} na Cloudflare (valor abaixo)") + ['record' => $record];
        }

        return $this->publicKey($published) === $this->publicKey($value)
            ? $this->state(true, 'publicado', $name) + ['record' => $record]
            : $this->state(false, 'chave diferente', "o TXT {$name} não é a chave atual do servidor: troque pelo valor abaixo") + ['record' => $record];
    }

    /**
     * O SPF do domínio precisa autorizar o IP público do servidor: o do nome do HELO
     * ou, quando esse nome resolve só para endereço interno (dentro do container, o
     * /etc/hosts do servidor o aponta para 127.0.1.1), o MAIL_PUBLIC_IP.
     */
    private function spf(string $domain): array
    {
        $spf = collect($this->dns->txt($domain))->first(fn ($txt) => str_starts_with(strtolower($txt), 'v=spf1'));
        $ip = collect($this->dns->ips((string) config('secmon.mail.helo')))
            ->first(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            ?? (config('secmon.mail.public_ip') ?: null);

        if (! $spf) {
            return $this->state(false, 'sem SPF', "{$domain} não tem registro v=spf1");
        }

        if ($ip && preg_match('/(^|\s)\+?ip4:'.preg_quote($ip, '/').'(\/32)?(\s|$)/', $spf)) {
            return $this->state(true, 'autoriza o servidor', $spf);
        }

        return $this->state(false, 'não autoriza o servidor', ($ip ? "falta ip4:{$ip} em " : 'o IP público do servidor não resolveu (defina MAIL_PUBLIC_IP); SPF: ').$spf);
    }

    private function dmarc(string $domain): array
    {
        $dmarc = collect($this->dns->txt('_dmarc.'.$domain))->first(fn ($txt) => str_starts_with(strtolower($txt), 'v=dmarc1'));

        return $dmarc
            ? $this->state(true, 'publicado', $dmarc)
            : $this->state(false, 'sem DMARC', "_dmarc.{$domain} não tem registro");
    }

    private function reader(bool $smtp): array
    {
        $at = $this->log->lastRun();

        if (! $at) {
            return $this->state(! $smtp, 'nunca leu', $smtp ? 'o secmon:mail-status (a cada minuto) ainda não achou o log do servidor de envio' : 'sem servidor de envio, não há log');
        }

        $when = Carbon::parse($at);
        $stale = $smtp && $when->lt(now()->subMinutes(self::READER_STALE_MINUTES));

        return $this->state(! $stale, $stale ? 'parado' : 'em dia', 'última leitura do log: '.$when->timezone('America/Sao_Paulo')->format('d/m H:i'));
    }

    private function file(string $path): ?string
    {
        $content = $path !== '' && is_readable($path) ? trim((string) file_get_contents($path)) : '';

        return $content === '' ? null : $content;
    }

    private function publicKey(string $record): string
    {
        return preg_match('/p=([A-Za-z0-9+\/=]+)/', str_replace([' ', '"'], '', $record), $m) ? $m[1] : '';
    }

    /** @return array{ok: bool, label: string, detail: string} */
    private function state(bool $ok, string $label, string $detail): array
    {
        return compact('ok', 'label', 'detail');
    }
}
