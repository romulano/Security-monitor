<?php

namespace App\Services\Github;

use RuntimeException;

/** O limite da API do GitHub acabou e a janela demora mais do que a varredura espera. */
class GithubRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("limite da API do GitHub esgotado (volta em {$retryAfter} s)");
    }
}
