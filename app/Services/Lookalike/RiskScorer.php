<?php

namespace App\Services\Lookalike;

use App\Models\LookalikeCapture;
use App\Models\LookalikeDomain;
use App\Support\RegistrableDomain;
use Illuminate\Support\Str;

/**
 * Do "só parecido" ao "atacante": soma sinais do DNS, do registro e da página
 * e dá o nível. Sem IA: comparar com a marca exigiria mandar a marca para ela,
 * e a /privacidade promete que isso não acontece.
 *
 * Página que não mostra nada (sem site, fora do ar, estacionada, em branco)
 * fica no máximo em "vigiar": o golpista costuma registrar, esperar e só
 * depois montar a página, e é a mudança de nível que vira aviso.
 */
class RiskScorer
{
    private const QUIET_STATES = ['pending', 'no_site', 'offline', 'parked', 'blank', 'challenge'];

    /** Sinais de que a página imita o cliente ou colhe dados: sem um deles, não passa de "vigiar". */
    private const IMITATION = ['hotlink', 'links_to_you', 'brand_title', 'brand_text', 'password', 'payment', 'looks_like_you'];

    /** Desafio anti-robô (Cloudflare e afins): a página existe, mas não mostra o conteúdo ao navegador. */
    private const CHALLENGE_TITLES = ['just a moment', 'um momento', 'attention required', 'verifying you are human', 'ddos-guard', 'checking your browser'];

    /** Situação da página a partir da visita do navegador. */
    public function stateFromCapture(LookalikeCapture $capture, BrandContext $brand): string
    {
        $data = $capture->data ?? [];

        if ($capture->status !== 'done') {
            return 'offline';
        }

        if (! empty($data['own_navigation']) || $brand->isOwn(parse_url((string) $capture->final_url, PHP_URL_HOST))) {
            return 'redirects_to_you';
        }

        if (! ($data['ok'] ?? false) || $capture->http_status >= 500) {
            return 'offline';
        }

        $title = BrandContext::normalize((string) $capture->title);

        foreach (self::CHALLENGE_TITLES as $challenge) {
            if (str_starts_with($title, $challenge)) {
                return 'challenge';
            }
        }

        if (in_array($capture->http_status, [404, 410], true)) {
            return 'blank';
        }

        if (Parking::looksParked($capture->title, $capture->text_excerpt, $data['resource_hosts'] ?? [], $capture->final_url)) {
            return 'parked';
        }

        $forms = $data['forms'] ?? [];

        if ((int) ($data['text_length'] ?? 0) < 40 && (int) ($forms['inputs'] ?? 0) === 0 && (int) ($data['images'] ?? 0) < 2) {
            return 'blank';
        }

        return 'content';
    }

    /** Recalcula sinais, pontos e nível. Devolve true se o nível mudou. */
    /** O sinal do nome que imita um subdomínio da empresa (E4b): pesa como a palavra de golpe no nome. */
    public static function subdomainSignal(string $seed): array
    {
        return ['key' => 'subdomain', 'label' => "Imita {$seed}", 'points' => 10];
    }

    public function apply(LookalikeDomain $lookalike, BrandContext $brand): bool
    {
        $signals = [];
        $add = function (string $key, string $label, int $points) use (&$signals) {
            $signals[] = ['key' => $key, 'label' => $label, 'points' => $points];
        };

        // A semente de subdomínio (E4b), gravada quando o nome foi achado: o subdomínio que ele imita.
        if ($seed = collect($lookalike->signals ?? [])->firstWhere('key', 'subdomain')) {
            $signals[] = $seed;
        }

        if ($lookalike->registered_at) {
            $days = (int) $lookalike->registered_at->diffInDays(now());

            match (true) {
                $days <= 30 => $add('recent', 'Registrado '.$lookalike->registeredAgo(), 25),
                $days <= 90 => $add('recent', 'Registrado '.$lookalike->registeredAgo(), 12),
                $days >= 730 => $add('old', 'Registrado '.$lookalike->registeredAgo(), -10),
                default => null,
            };
        }

        if ($lookalike->hasMx()) {
            $add('mx', 'Pode mandar e-mail em nome dele (tem MX)', 15);
        }

        if ($lookalike->from_certificate) {
            $add('certificate', 'Apareceu num certificado HTTPS com o nome da marca', 5);
        }

        $techniques = $lookalike->techniques ?? [];

        if (array_intersect($techniques, ['parecida', 'outro-alfabeto', 'acento'])) {
            $add('deceptive', 'Engana a olho nu (letra parecida)', 10);
        }

        if (in_array('palavra', $techniques, true)) {
            $add('keyword', 'Nome com palavra de golpe (login, pix, suporte…)', 10);
        }

        if ($brand->ns && $lookalike->ns && ! array_diff($lookalike->ns, $brand->ns) && ! array_diff($brand->ns, $lookalike->ns)) {
            $add('same_ns', 'Usa os mesmos servidores de DNS que o seu domínio', -20);
        }

        if ($brand->ips && array_intersect($lookalike->ips ?? [], $brand->ips)) {
            $add('same_ip', 'Aponta para o mesmo IP do seu site', -20);
        }

        $capture = $lookalike->lastCapture;
        $data = $capture?->status === 'done' ? ($capture->data ?? []) : [];
        $hotlinks = 0;
        $asks = false;

        if ($data) {
            $own = collect($data['own_requests'] ?? [])->reject(fn ($r) => $r['navigation'] ?? false);
            $hotlinks = $own->count();

            if ($hotlinks > 0) {
                $add('hotlink', 'A página puxa '.($hotlinks === 1 ? '1 arquivo' : "{$hotlinks} arquivos").' do seu site (bloqueamos)', 35);
            }

            // Manda o visitante para outro domínio (fora estacionamento): revenda de tráfego, anúncio, golpe.
            $final = strtolower((string) parse_url((string) $capture->final_url, PHP_URL_HOST));
            $finalSite = RegistrableDomain::of($final) ?? $final;

            if ($final !== '' && $lookalike->state === 'content' && $finalSite !== (RegistrableDomain::of($lookalike->host) ?? $lookalike->host) && ! $brand->isOwn($final)) {
                $add('redirects_elsewhere', "Manda o visitante para outro site ({$finalSite})", 10);
            }

            if (collect($data['link_hosts'] ?? [])->contains(fn ($h) => $brand->isOwn($h))) {
                $add('links_to_you', 'Tem links para o seu site', 10);
            }

            if ($brand->mentions($capture->title)) {
                $add('brand_title', 'Usa o nome da marca no título', 20);
            } elseif ($brand->mentions($capture->text_excerpt)) {
                $add('brand_text', 'Cita a marca no texto', 10);
            }

            // Comparação com os prints de referência do site do cliente (mesmo aparelho).
            if ($similar = AlertPhoto::mostSimilar($capture)) {
                $page = $lookalike->watch?->referencePages()[$similar['page']]['title'] ?? null;
                $add('looks_like_you', sprintf('Parece com a sua página%s (%d%%) no %s', $page ? ' “'.Str::limit($page, 40).'”' : '', $similar['score'], strtolower(LookalikeCapture::DEVICES[$similar['device']])), $similar['score'] >= 95 ? 35 : 25);
            }

            // O golpe aparece só no celular: o site esconde o conteúdo de quem abre no computador.
            if (AlertPhoto::deviceScore($capture, $brand, 'desktop') === 0 && ($mobile = AlertPhoto::scamDevice($capture, $brand)) && $mobile !== 'desktop') {
                $add('mobile_only', 'Mostra o conteúdo suspeito só no '.LookalikeCapture::DEVICES[$mobile], 10);
            }

            $forms = $data['forms'] ?? [];

            // Levou para outro site e nada lembra a marca: o formulário é do outro site (cadastro de
            // afiliado, anúncio), não uma cópia do cliente pedindo dados.
            $branded = array_intersect(['hotlink', 'links_to_you', 'brand_title', 'brand_text'], array_column($signals, 'key'));
            $elsewhere = in_array('redirects_elsewhere', array_column($signals, 'key'), true);

            if ($elsewhere && ! $branded) {
                $forms = [];
            }

            if (($forms['password'] ?? 0) > 0) {
                $add('password', 'Pede senha', 20);
                $asks = true;
            }

            if (($forms['card'] ?? 0) + ($forms['cpf'] ?? 0) + ($forms['pix'] ?? 0) > 0) {
                $add('payment', 'Pede '.collect(['card' => 'cartão', 'cpf' => 'CPF', 'pix' => 'Pix'])->filter(fn ($l, $k) => ($forms[$k] ?? 0) > 0)->join(', ', ' ou '), 25);
                $asks = true;
            }
        }

        if ($lookalike->state === 'redirects_to_you') {
            $add('redirects_to_you', 'Redireciona para o seu site', -30);
        }

        $score = max(0, min(100, array_sum(array_column($signals, 'points'))));
        $risk = $this->risk($lookalike, $score, $hotlinks > 0 && $asks, $signals);

        // A primeira classificação (saindo de "aguardando a visita") não é mudança de nível.
        $old = $lookalike->exists && $lookalike->getOriginal('state') !== 'pending' ? $lookalike->getOriginal('risk') : null;
        $changed = $old !== null && $old !== $risk;
        $lookalike->fill([
            'signals' => $signals,
            'score' => $score,
            'risk' => $risk,
        ]);

        if ($changed) {
            $lookalike->previous_risk = $old;
            $lookalike->risk_changed_at = now();
        }

        return $changed;
    }

    private function risk(LookalikeDomain $lookalike, int $score, bool $cloneAsking, array $signals): string
    {
        $has = fn (string $key) => in_array($key, array_column($signals, 'key'), true);

        if ($lookalike->verdict === 'threat') {
            return 'critical';
        }

        if (in_array($lookalike->state, ['redirects_to_you', 'gone'], true)) {
            return 'low';
        }

        if (in_array($lookalike->state, self::QUIET_STATES, true)) {
            // Mesmo DNS ou mesmo IP do cliente: quase certo que é registro defensivo dele.
            if ($has('same_ns') || $has('same_ip')) {
                return 'low';
            }

            return $has('old') && ! $lookalike->hasMx() && $lookalike->state !== 'pending' ? 'low' : 'watch';
        }

        // Site no ar sem nada da marca e sem pedir dados: só parecido. Recém-registrado, ou desviando
        // para outro site quem erra o nome (afiliado, anúncio), ainda merece olho.
        if (! array_intersect(self::IMITATION, array_column($signals, 'key'))) {
            return $has('redirects_elsewhere') || ($has('recent') && $lookalike->registered_at?->gt(now()->subDays(30))) ? 'watch' : 'low';
        }

        return match (true) {
            $cloneAsking || $score >= 60 => 'critical',
            $score >= 35 => 'high',
            $score >= 15 => 'watch',
            default => 'low',
        };
    }
}
