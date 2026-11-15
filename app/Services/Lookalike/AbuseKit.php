<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeDomain;

/**
 * Kit de denúncia: a quem escrever (registrador, hospedagem, CERT.br), onde
 * reportar (Google, Microsoft, Cloudflare) e o texto pronto com as provas.
 * O Security Monitor não envia nada: quem denuncia é o cliente, como titular da marca.
 */
class AbuseKit
{
    /** Por onde o cliente pode denunciar; ele marca cada um ao enviar, para acompanhar. */
    public const CHANNELS = [
        'registrar' => 'Registrador',
        'hosting' => 'Hospedagem',
        'certbr' => 'CERT.br',
        'google' => 'Google Safe Browsing',
        'microsoft' => 'Microsoft',
        'cloudflare' => 'Cloudflare',
    ];

    /** @return array{contacts: list<array>, links: list<array>, text_pt: string, text_en: string, url: string} */
    public function for(LookalikeDomain $lookalike): array
    {
        $company = $lookalike->watch->companyName();
        $client = $lookalike->watch->domain->domain;
        $capture = $lookalike->lastCapture?->status === 'done' ? $lookalike->lastCapture : null;
        $url = $capture?->final_url ?: 'https://'.$lookalike->host.'/';
        $rdap = $lookalike->rdap ?? [];
        $hosting = $lookalike->hosting ?? [];
        $hostingName = $hosting['org'] ?? $hosting['network'] ?? null;
        $cloudflare = str_contains(strtolower((string) $hostingName), 'cloudflare');

        $contacts = array_values(array_filter([
            ! empty($rdap['abuse_email']) && $rdap['abuse_email'] !== 'cert@cert.br' ? [
                'key' => 'registrar',
                'label' => 'Registrador'.(! empty($rdap['registrar']) ? ' · '.$rdap['registrar'] : ''),
                'email' => $rdap['abuse_email'],
                'why' => 'pode suspender o domínio',
            ] : null,
            ! empty($hosting['abuse_email']) ? [
                'key' => 'hosting',
                'label' => 'Hospedagem'.($hostingName ? ' · '.$hostingName : ''),
                'email' => $hosting['abuse_email'],
                'why' => $cloudflare ? 'a Cloudflare repassa à hospedagem real e pode avisar quem abrir a página' : 'pode tirar a página do ar',
            ] : null,
            [
                'key' => 'certbr',
                'label' => 'CERT.br',
                'email' => 'cert@cert.br',
                'why' => 'centro de resposta a incidentes do Brasil; aciona os responsáveis',
            ],
        ]));

        $links = array_values(array_filter([
            ['key' => 'google', 'label' => 'Google Safe Browsing', 'url' => 'https://safebrowsing.google.com/safebrowsing/report_phish/?hl=pt-BR&url='.rawurlencode($url), 'why' => 'o Chrome e o Android passam a avisar quem abrir'],
            ['key' => 'microsoft', 'label' => 'Microsoft (Edge e Outlook)', 'url' => 'https://www.microsoft.com/en-us/wdsi/support/report-unsafe-site-guest', 'why' => 'bloqueio no Edge e no filtro de e-mail'],
            $cloudflare ? ['key' => 'cloudflare', 'label' => 'Cloudflare', 'url' => 'https://abuse.cloudflare.com/', 'why' => 'o site está atrás da Cloudflare'] : null,
        ]));

        $evidence = $this->evidence($lookalike, $url);
        // Nome com acento ou outro alfabeto: o registrador precisa do punycode; quem lê, de como ele aparece.
        $shown = $lookalike->isIdn() ? " (aparece como {$lookalike->displayHost()})" : '';
        $shownEn = $lookalike->isIdn() ? " (displayed as {$lookalike->displayHost()})" : '';
        $signalsPt = collect($lookalike->signals ?? [])->where('points', '>', 0)->pluck('label')->map(fn ($l) => '- '.$l)->implode("\n");
        $date = ($capture?->captured_at ?? now())->timezone('America/Sao_Paulo')->format('d/m/Y H:i');

        $textPt = trim(<<<TXT
            Assunto: Denúncia de phishing e uso indevido de marca: {$lookalike->host}

            Olá,

            Somos a {$company}, titular do domínio {$client}. O domínio {$lookalike->host}{$shown} imita o nosso e pode ser usado para enganar nossos clientes.

            Evidências (coletadas em {$date}, horário de Brasília):
            {$evidence['pt']}
            {$signalsPt}

            Pedimos a suspensão do domínio e da hospedagem. Os prints da página seguem em anexo.

            Atenciosamente,
            {$company}
            TXT);

        $textEn = trim(<<<TXT
            Subject: Phishing / brand abuse report: {$lookalike->host}

            Hello,

            We are {$company}, the owner of {$client}. The domain {$lookalike->host}{$shownEn} impersonates ours and may be used to defraud our customers.

            Evidence (collected on {$date}, UTC-3):
            {$evidence['en']}

            We request the suspension of the domain and of the hosting. Screenshots of the page are attached.

            Best regards,
            {$company}
            TXT);

        return ['contacts' => $contacts, 'links' => $links, 'text_pt' => $textPt, 'text_en' => $textEn, 'url' => $url];
    }

    /**
     * "Enviei" num caminho do kit (ou desfaz); devolve se ficou marcado como
     * enviado. Denunciado, o Radar passa a olhar o domínio todo dia até ele cair.
     */
    public function toggleSent(LookalikeDomain $lookalike, string $channel): bool
    {
        $reports = $lookalike->reports ?? [];
        $done = ! empty($reports[$channel]['at']);
        $reports[$channel] = $done ? null : ['at' => now()->toIso8601String()];
        $lookalike->forceFill(['reports' => array_filter($reports)])->save();

        return ! $done;
    }

    /** @return array{pt: string, en: string} */
    private function evidence(LookalikeDomain $lookalike, string $url): array
    {
        $pt = ["- Endereço: {$url}"];
        $en = ["- URL: {$url}"];

        if ($lookalike->ips) {
            $pt[] = '- IP: '.implode(', ', $lookalike->ips);
            $en[] = '- IP address: '.implode(', ', $lookalike->ips);
        }

        if ($lookalike->registered_at) {
            $pt[] = '- Registrado em: '.$lookalike->registered_at->format('d/m/Y');
            $en[] = '- Registered on: '.$lookalike->registered_at->format('Y-m-d');
        }

        if ($lookalike->hasMx()) {
            $pt[] = '- Tem servidor de e-mail (MX): '.implode(', ', $lookalike->mx);
            $en[] = '- Has mail exchanger (MX): '.implode(', ', $lookalike->mx);
        }

        $keys = array_column($lookalike->signals ?? [], 'key');
        $map = [
            'hotlink' => 'The page loads images or files directly from our website',
            'brand_title' => 'The page title uses our brand name',
            'brand_text' => 'The page text mentions our brand',
            'password' => 'The page asks for a password',
            'payment' => 'The page asks for card, CPF (Brazilian tax ID) or Pix (instant payment) data',
            'deceptive' => 'The name uses look-alike characters to pass as ours',
            'keyword' => 'The name combines our brand with words used in scams (login, support, payment)',
        ];

        foreach ($map as $key => $line) {
            if (in_array($key, $keys, true)) {
                $en[] = '- '.$line;
            }
        }

        return ['pt' => implode("\n", $pt), 'en' => implode("\n", $en)];
    }
}
