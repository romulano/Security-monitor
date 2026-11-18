<?php

namespace App\Services\Leaks;

use App\Models\FreeMailDomain;
use Illuminate\Support\Facades\Cache;

/**
 * Responde "este domínio é de webmail grátis?" a partir da tabela que o admin mantém.
 *
 * A carga pergunta isto uma vez por linha — milhões de vezes — então a lista é lida uma vez só: fica
 * no cache (invalidado quando o admin mexe) e memorizada na instância, que vive o processo inteiro
 * da importação. Nunca uma consulta por linha.
 */
final class FreeMailList
{
    private const CACHE = 'leaks.free-mail-domains';

    /** @var array<string,true>|null */
    private ?array $domains = null;

    /** @var array<string,true>|null */
    private ?array $brands = null;

    public function has(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain, " \t."));

        if ($domain === '') {
            return false;
        }

        $this->load();

        if (isset($this->domains[$domain])) {
            return true;
        }

        // Regra da marca: `marca.<sufixo público>`, com no máximo dois rótulos de sufixo.
        $labels = explode('.', $domain);
        $brand = array_shift($labels);

        if (! isset($this->brands[$brand]) || $labels === [] || count($labels) > 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (! Tlds::isSuffix($label)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{domains:int,brands:int} */
    public function counts(): array
    {
        $this->load();

        return ['domains' => count($this->domains), 'brands' => count($this->brands)];
    }

    /** Chamado pelo admin depois de cadastrar ou remover: a próxima carga já lê a lista nova. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    private function load(): void
    {
        if ($this->domains !== null) {
            return;
        }

        [$this->domains, $this->brands] = Cache::rememberForever(self::CACHE, function (): array {
            $rows = FreeMailDomain::query()->get(['kind', 'value']);

            return [
                array_fill_keys($rows->where('kind', 'domain')->pluck('value')->all(), true),
                array_fill_keys($rows->where('kind', 'brand')->pluck('value')->all(), true),
            ];
        });
    }
}
