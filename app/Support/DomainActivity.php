<?php

namespace App\Support;

use App\Models\PentestRun;

/**
 * A trava entre a stack de testes e o que mais lê o site do cliente (T10): a
 * descoberta de Notícias e do Radar, os prints do Radar e a monitoria do admin
 * esperam enquanto houver um teste de segurança rodando ou parando no mesmo
 * domínio. A execução pausada não segura (C7 do Red Team): a pausa do cliente só
 * volta com ele, e seguraria a leitura passiva até o fim da validade. No sentido
 * contrário, o portão só deixa uma ação sair com a trava da descoberta
 * (`alerts-discovery:<domínio>`) livre, e a descoberta confere esta trava já com
 * a dela na mão: um dos dois sempre vê o outro.
 */
final class DomainActivity
{
    /** Há teste de segurança ativo em `$host` ou num domínio que o cobre (`loja.empresa.com.br` é coberto por `empresa.com.br`)? */
    public static function pentestActive(string $host): bool
    {
        $labels = explode('.', strtolower(rtrim(trim($host), '.')));

        if (count($labels) < 2 || in_array('', $labels, true)) {
            return false;
        }

        // O próprio nome e cada domínio acima dele, até o de dois rótulos (com.br não é domínio de ninguém, mas é inofensivo).
        $names = array_map(fn (int $i) => implode('.', array_slice($labels, $i)), range(0, count($labels) - 2));

        return PentestRun::query()->whereIn('status', ['running', 'stopping'])->whereIn('domain', $names)->exists();
    }
}
