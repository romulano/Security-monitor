<?php

namespace App\Services\Security\Checks;

use App\Services\Security\CheckResult;
use App\Services\Security\SiteProbe;

/**
 * Arquivos que nunca deveriam ser públicos. Um GET por caminho, no máximo 4 KB, e só vale quando o
 * conteúdo tem a ASSINATURA do arquivo (um site que responde 200 com a página inicial para qualquer
 * endereço não é um vazamento). Guarda só "exposto" e o caminho: nunca o conteúdo.
 */
class ExposureCheck implements SiteCheck
{
    /** @var array<string, array{name: string, signature: string}> caminho => o que é e o regex que o identifica */
    private const PATHS = [
        '/.env' => ['name' => 'arquivo .env', 'signature' => '/^\s*(APP_KEY|APP_ENV|DB_PASSWORD|DB_HOST|SECRET_KEY|AWS_SECRET_ACCESS_KEY)\s*=/m'],
        '/.git/HEAD' => ['name' => 'repositório git', 'signature' => '/^ref:\s*refs\//'],
        '/storage/logs/laravel.log' => ['name' => 'log do Laravel', 'signature' => '/\[\d{4}-\d{2}-\d{2} [\d:]+\] \w+\.(ERROR|WARNING|INFO|DEBUG)|#0 \/.*\.php\(\d+\)/'],
        '/phpinfo.php' => ['name' => 'phpinfo()', 'signature' => '/phpinfo\(\)|PHP Version \d/i'],
        '/server-status' => ['name' => 'server-status do Apache', 'signature' => '/Apache Server Status|Server Version: Apache/i'],
        '/.DS_Store' => ['name' => '.DS_Store', 'signature' => '/^\x00\x00\x00\x01Bud1/'],
        '/backup.sql' => ['name' => 'dump de banco', 'signature' => '/-- (MySQL|PostgreSQL) dump|CREATE TABLE|INSERT INTO/i'],
        '/backup.zip' => ['name' => 'backup compactado', 'signature' => '/^PK\x03\x04/'],
    ];

    public function ids(): array
    {
        return ['file.exposed'];
    }

    public function run(SiteProbe $probe): array
    {
        $results = [];
        $reached = 0;

        foreach (self::PATHS as $path => $file) {
            $response = $probe->fetch($path, (int) config('secmon.security.probe_bytes', 4096));

            if ($response['blocked']) {
                return [CheckResult::untested('file.exposed', (string) $response['error'])];
            }

            if (! $probe->answered($response)) {
                continue;
            }

            $reached++;

            if ((int) $response['status'] === 200 && preg_match($file['signature'], (string) $response['body'])) {
                $results[] = CheckResult::critical('file.exposed', "{$file['name']} acessível", $path);
            }
        }

        if ($reached === 0) {
            return [CheckResult::untested('file.exposed', 'o site não respondeu')];
        }

        return $results ?: [CheckResult::ok('file.exposed', count(self::PATHS).' caminhos conferidos, nenhum exposto')];
    }
}
