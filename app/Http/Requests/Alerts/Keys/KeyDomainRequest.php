<?php

namespace App\Http\Requests\Alerts\Keys;

use App\Http\Requests\Request;
use App\Models\OwnedDomain;
use App\Support\DomainName;

/**
 * Pedido sobre um domínio comprovado na área "Chaves expostas", pelo nome na rota (`{dominio}`).
 * Só o comprovado que a conta vê (o de outra conta, o não comprovado e o que não existe são 404).
 * O que muda alguma coisa (varrer, pausar, decidir) pede também que a conta configure o domínio.
 */
abstract class KeyDomainRequest extends Request
{
    private ?OwnedDomain $owned = null;

    private bool $loaded = false;

    /** Ver basta (a página); mudar pede configurar (na empresa: a admin e quem responde pelo setor). */
    protected bool $changes = false;

    public function authorize(): bool
    {
        return $this->owned() !== null;
    }

    public function owned(): ?OwnedDomain
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $name = $this->route('dominio');
            $query = OwnedDomain::query()->whereNotNull('verified_at')->listedInKeys();
            $this->changes ? $query->manageableBy($this->user()) : $query->visibleTo($this->user());
            $this->owned = is_string($name) && strlen($name) <= 253 && DomainName::isValid($name) ? $query->where('domain', $name)->oldest('id')->first() : null;
        }

        return $this->owned;
    }
}
