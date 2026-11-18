<?php

namespace App\Services\Admin;

use App\Models\FreeMailDomain;
use App\Models\LeakDump;
use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Models\User;
use App\Services\Leaks\FreeMailList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** O que Admin → Vazamentos mostra e faz. */
class LeakPanel
{
    public function __construct(private FreeMailList $free) {}

    /** @return array{pending:int,resolved:int} */
    public function counts(): array
    {
        $rows = LeakImportLine::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0),
            'resolved' => (int) ($rows['resolved'] ?? 0),
        ];
    }

    /** As linhas da aba pedida, mais velhas primeiro: a fila é por ordem de chegada. */
    public function lines(string $status): LengthAwarePaginator
    {
        return LeakImportLine::query()
            ->with('resolver:id,name')
            ->where('status', $status)
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * As credenciais guardadas, 100 por página, da mais nova para a mais velha.
     *
     * A ordem é por `id` decrescente, e não por `created_at`: a tabela não tem `updated_at` (a linha
     * é um fato imutável) e o `id` é monotônico, então dá exatamente a mesma ordem de chegada — só
     * que pela chave primária, sem ordenar milhões de linhas por uma coluna sem índice.
     *
     * A busca é por COMEÇO do texto, de propósito: `like 'x%'` usa os índices, enquanto `%x%` faria
     * varredura completa. O `@` escolhe o campo: começando com ele (`@acme.com.br`) procura o
     * domínio do e-mail; no meio (`ana@acme`) procura o e-mail inteiro; sem ele, procura domínio,
     * site e nome do dump.
     */
    public function credentials(?string $term): LengthAwarePaginator
    {
        $term = trim((string) $term);

        return LeakedCredential::query()
            ->when($term !== '', function ($query) use ($term): void {
                $prefix = mb_strtolower($term).'%';

                // `@acme.com.br` é como se escreve "todo mundo desse domínio". Sem isto o termo
                // caía na busca por e-mail e não achava nada, porque e-mail nenhum começa com `@`.
                if (str_starts_with($term, '@')) {
                    $query->where('domain', 'like', mb_strtolower(ltrim($term, '@')).'%');

                    return;
                }

                if (str_contains($term, '@')) {
                    $query->where('email', 'like', $prefix);

                    return;
                }

                $query->where(fn ($w) => $w
                    ->where('domain', 'like', $prefix)
                    ->orWhere('site_host', 'like', $prefix)
                    ->orWhere('source', $term));
            })
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();
    }

    /** @return Collection<int,FreeMailDomain> */
    public function freeMail(string $kind): Collection
    {
        return FreeMailDomain::query()
            ->with('author:id,name')
            ->where('kind', $kind)
            ->orderBy('value')
            ->get();
    }

    /** @return array{domains:int,brands:int} */
    public function freeMailCounts(): array
    {
        return $this->free->counts();
    }

    /** Quantas credenciais cada dump rendeu, para a tela não ficar cega sobre o resultado. */
    public function sources(): Collection
    {
        return LeakedCredential::query()
            ->selectRaw('source, count(*) as total, count(distinct domain) as domains, max(created_at) as last_at')
            ->groupBy('source')
            ->orderByDesc('last_at')
            ->get();
    }

    /** Os últimos arquivos que o admin mandou carregar, com o resultado de cada um. */
    public function dumps(int $limit = 30): Collection
    {
        return LeakDump::query()
            ->with('requester:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * O humano decidiu quem é quem: grava a credencial e fecha a linha.
     *
     * A regra do banco é a mesma da carga — só e-mail de empresa entra. Se o login não for e-mail,
     * ou for webmail genérico, não há o que gravar: a linha se descarta. Devolve o motivo para a
     * tela em vez de estourar, porque isto é decisão do admin, não erro de programa.
     *
     * @param  array{login:string,password:string,url:?string}  $data
     * @return array{ok:bool,error:?string}
     */
    public function classify(LeakImportLine $line, array $data, User $admin): array
    {
        if ($line->status !== 'pending') {
            return ['ok' => false, 'error' => 'Esta linha já foi decidida.'];
        }

        $login = trim($data['login']);
        $email = mb_strtolower($login);

        if (preg_match('/^[^@\s]+@[a-z0-9]([a-z0-9.\-]*[a-z0-9])?\.[a-z]{2,}$/iu', $login) !== 1) {
            return ['ok' => false, 'error' => 'O login precisa ser um e-mail: a tabela guarda credencial de empresa, identificada pelo domínio. Se este login não é e-mail, descarte a linha.'];
        }

        $domain = substr($email, strrpos($email, '@') + 1);

        if ($this->free->has($domain)) {
            return ['ok' => false, 'error' => "O domínio {$domain} está na lista de e-mail genérico, e genérico não é guardado. Descarte a linha — ou tire {$domain} da lista, se ele for de empresa."];
        }

        $url = $data['url'] === null || trim($data['url']) === '' ? null : trim($data['url']);

        DB::transaction(function () use ($line, $login, $email, $domain, $url, $data, $admin): void {
            $credential = LeakedCredential::query()->firstOrCreate(
                [
                    'source' => $line->source,
                    'fingerprint' => LeakedCredential::fingerprintFor($url, $login, $data['password']),
                ],
                [
                    'domain' => $domain,
                    'email' => $email,
                    'password' => $data['password'],
                    'url' => $url,
                    'site_host' => $url === null ? null : (parse_url($url, PHP_URL_HOST) ?: null),
                    'country' => $line->country,
                ],
            );

            $line->update([
                'status' => 'resolved',
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'leaked_credential_id' => $credential->id,
            ]);
        });

        return ['ok' => true, 'error' => null];
    }

    /**
     * Lixo, banner do dump, linha truncada: APAGA.
     *
     * Não vira `discarded` guardado. O admin já disse que não é credencial, e manter a linha só
     * encheria a tabela de coisa que ninguém vai reler. O que fica registrado é o contrário: a
     * linha que VIROU credencial, em `resolved`, apontando para ela.
     */
    public function discard(LeakImportLine $line, User $admin): bool
    {
        if ($line->status !== 'pending') {
            return false;
        }

        $line->delete();

        return true;
    }

    /** @param  array{kind:string,value:string,note:?string}  $data */
    public function addFreeMail(array $data, User $admin): FreeMailDomain
    {
        $row = FreeMailDomain::query()->create([
            'kind' => $data['kind'],
            'value' => mb_strtolower(trim($data['value'])),
            'note' => $data['note'],
            'created_by' => $admin->id,
        ]);

        FreeMailList::forget();

        return $row;
    }

    public function removeFreeMail(FreeMailDomain $row): void
    {
        $row->delete();

        FreeMailList::forget();
    }
}
