<?php

namespace App\Services\Github;

use RuntimeException;

/** A busca não tem como rodar (sem token, token recusado): a varredura registra e segue com o resto. */
class GithubUnavailable extends RuntimeException {}
