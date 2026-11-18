<?php

namespace App\Console\Commands;

use App\Models\LeakedCredential;
use App\Models\LeakImportLine;
use App\Services\Leaks\CredentialLineParser;
use App\Services\Leaks\FreeMailList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aplica a lista de e-mail genérico ao que já está no banco: a fila do humano e as credenciais.
 *
 * Serve toda vez que o admin mexe na lista — cadastrar um prefixo não desfaz o que já entrou. São
 * duas limpezas independentes:
 *
 * - FILA: sai a linha que nenhum humano resolveria, porque não tem e-mail de empresa nela (a
 *   tabela exige e-mail e genérico é descartado, então não há decisão que gere credencial).
 * - CREDENCIAIS: sai a linha cujo domínio a lista de hoje considera genérico.
 *
 * Sem `--aplicar` NADA é apagado: ele conta e mostra os domínios mais atingidos. Isso não é
 * frescura — prefixo casa pelo começo, e um `mail.` cadastrado sem querer apagaria
 * `mail.universidade.edu`. Olhe a lista antes.
 */
class LeaksCleanCommand extends Command
{
    protected $signature = 'leaks:limpar
        {--aplicar : apaga de verdade; sem isto o comando só conta}
        {--so-fila : mexe apenas na fila do humano}
        {--so-credenciais : mexe apenas nas credenciais guardadas}
        {--so-repetidos : mexe apenas nas credenciais repetidas}';

    protected $description = 'Aplica a lista de e-mail genérico à fila e às credenciais já guardadas';

    public function handle(CredentialLineParser $parser, FreeMailList $free): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $so = $this->option('so-fila') || $this->option('so-credenciais') || $this->option('so-repetidos');
        $fila = ! $so || $this->option('so-fila');
        $credenciais = ! $so || $this->option('so-credenciais');
        $repetidos = ! $so || $this->option('so-repetidos');

        $resumo = [];

        if ($fila) {
            $resumo[] = $this->limparFila($parser, $aplicar);
        }

        if ($credenciais) {
            $resumo[] = $this->limparCredenciais($free, $aplicar);
        }

        if ($repetidos) {
            $resumo[] = $this->limparRepetidos($aplicar);
        }

        $this->newLine();
        $this->table(['o que', 'fica', $aplicar ? 'APAGADO' : 'sairia'], $resumo);

        if (! $aplicar && array_sum(array_column($resumo, 2)) > 0) {
            $this->newLine();
            $this->warn('Nada foi alterado. Confira os domínios acima e rode de novo com --aplicar.');
        }

        return self::SUCCESS;
    }

    /** @return array{0:string,1:int,2:int} */
    private function limparFila(CredentialLineParser $parser, bool $aplicar): array
    {
        $this->info('Fila do humano...');
        $fica = $sai = 0;

        LeakImportLine::query()->where('status', 'pending')->orderBy('id')
            ->chunkById(500, function ($linhas) use ($parser, $aplicar, &$fica, &$sai): void {
                $apagar = [];

                foreach ($linhas as $linha) {
                    if ($parser->parse($linha->raw)['company_email'] !== null) {
                        $fica++;

                        continue;
                    }

                    $sai++;
                    $apagar[] = $linha->id;
                }

                if ($aplicar && $apagar !== []) {
                    DB::table('leak_import_lines')->whereIn('id', $apagar)->delete();
                }
            });

        return ['linhas na fila', $fica, $sai];
    }

    /**
     * Mesmo login, mesma senha e mesmo site, no mesmo dump: sobra uma.
     *
     * A chave única é (`source`, `fingerprint`), e a impressão digital inclui a URL INTEIRA. Então
     * `netflix.com/login` e `netflix.com/password`, do mesmo usuário e com a mesma senha, viram
     * duas linhas — e são a mesma credencial. Agrupa pelo host do site, não pela URL, e guarda a
     * mais antiga (menor id), que é a que a fila e a tela já referenciam.
     *
     * Dumps diferentes NÃO são juntados de propósito: saber em quantos vazamentos a senha queimou é
     * justamente o que a chave por `source` preserva.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function limparRepetidos(bool $aplicar): array
    {
        $this->info('Credenciais repetidas (mesmo login, senha e site)...');

        $sql = <<<'SQL'
            select count(*) as total from leaked_credentials a
            where exists (
                select 1 from leaked_credentials b
                where b.source = a.source
                  and b.email = a.email
                  and b.password = a.password
                  and coalesce(b.site_host, '') = coalesce(a.site_host, '')
                  and b.id < a.id
            )
        SQL;

        $sai = (int) (DB::selectOne($sql)->total ?? 0);

        if ($sai === 0) {
            $this->line('  nenhuma repetida.');
        } else {
            $this->line('  '.number_format($sai, 0, ',', '.').' repetida(s) — fica a mais antiga de cada grupo.');
        }

        if ($aplicar && $sai > 0) {
            DB::statement(<<<'SQL'
                delete from leaked_credentials a
                using leaked_credentials b
                where b.source = a.source
                  and b.email = a.email
                  and b.password = a.password
                  and coalesce(b.site_host, '') = coalesce(a.site_host, '')
                  and b.id < a.id
            SQL);
        }

        return ['credenciais repetidas', LeakedCredential::count() - ($aplicar ? 0 : $sai), $sai];
    }

    /** @return array{0:string,1:int,2:int} */
    private function limparCredenciais(FreeMailList $free, bool $aplicar): array
    {
        $this->info('Credenciais guardadas...');

        // Um domínio por vez, não uma linha por vez: são 171 mil domínios para 604 mil linhas, e
        // `domain` é indexado. Perguntar à lista uma vez por domínio é 3,5× menos trabalho.
        $genericos = LeakedCredential::query()->distinct()->pluck('domain')
            ->filter(fn (string $domain) => $free->has($domain))
            ->values();

        if ($genericos->isEmpty()) {
            $this->line('  nenhum domínio guardado está na lista de genérico.');

            return ['credenciais', LeakedCredential::count(), 0];
        }

        $sai = 0;
        $porDominio = [];

        foreach ($genericos->chunk(1000) as $lote) {
            $contas = LeakedCredential::query()->whereIn('domain', $lote)
                ->selectRaw('domain, count(*) as total')->groupBy('domain')->pluck('total', 'domain');

            foreach ($contas as $domain => $total) {
                $porDominio[$domain] = (int) $total;
                $sai += (int) $total;
            }

            if ($aplicar) {
                LeakedCredential::query()->whereIn('domain', $lote)->delete();
            }
        }

        arsort($porDominio);
        $this->line('  '.count($porDominio).' domínio(s) da lista, '.number_format($sai, 0, ',', '.').' credencial(is). Os maiores:');

        foreach (array_slice($porDominio, 0, 15, true) as $domain => $total) {
            $this->line(sprintf('    %-45s %s', $domain, number_format($total, 0, ',', '.')));
        }

        return ['credenciais', LeakedCredential::count() - ($aplicar ? 0 : $sai), $sai];
    }
}
