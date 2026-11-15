<?php

namespace App\Http\Requests\Admin\Monitoring;

use App\Http\Requests\Request;
use App\Support\IpList;
use Closure;

/** A lista de IPs "nossos" da tela de acessos: um IP ou faixa (CIDR) por linha. */
class UpdateOwnAddressesRequest extends Request
{
    public function rules(): array
    {
        return [
            'enderecos' => ['nullable', 'string', 'max:4000', function (string $attribute, mixed $value, Closure $fail) {
                $bad = array_diff($this->entries(), IpList::ipsAndRanges($this->entries()));

                if ($bad !== []) {
                    $fail('Não é IP nem faixa (CIDR): '.implode(', ', array_slice($bad, 0, 5)).'.');
                }
            }],
        ];
    }

    /** @return list<string> as linhas (vírgula e espaço também separam), sem as vazias */
    public function entries(): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', (string) $this->input('enderecos', '')) ?: []));
    }
}
