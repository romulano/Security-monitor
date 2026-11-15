<?php

namespace App\Http\Requests\Alerts\Keys;

use Illuminate\Validation\Validator;

/**
 * Os endereços extras da varredura: um por linha, caminho (/app/facial-auth/) ou URL do próprio
 * domínio ou de um subdomínio dele, até 50. Linha vazia e repetida somem.
 */
class UpdateKeyDomainPathsRequest extends KeyDomainRequest
{
    public const MAX = 50;

    protected bool $changes = true;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['paths' => ['nullable', 'string', 'max:20000']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $lines = $this->lines();

            if (count($lines) > self::MAX) {
                $validator->errors()->add('paths', 'No máximo '.self::MAX.' endereços.');

                return;
            }

            foreach ($lines as $line) {
                if (! $this->valid($line)) {
                    $validator->errors()->add('paths', '"'.mb_substr($line, 0, 80)."\" não é um caminho (/assim) nem um endereço de {$this->owned()?->domain}.");

                    return;
                }
            }
        }];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->lines();
    }

    /** @return list<string> */
    private function lines(): array
    {
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', (string) $this->input('paths', '')) ?: []);

        return array_values(array_unique(array_filter($lines, fn (string $line) => $line !== '')));
    }

    private function valid(string $line): bool
    {
        if (strlen($line) > 300 || preg_match('/\s/', $line)) {
            return false;
        }

        if (str_starts_with($line, '/')) {
            return ! str_starts_with($line, '//');
        }

        $host = strtolower((string) parse_url($line, PHP_URL_HOST));
        $domain = (string) $this->owned()?->domain;

        return preg_match('~^https?://~i', $line) === 1 && ($host === $domain || str_ends_with($host, '.'.$domain));
    }
}
