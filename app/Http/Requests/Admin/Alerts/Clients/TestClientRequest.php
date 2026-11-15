<?php

namespace App\Http\Requests\Admin\Alerts\Clients;

use App\Http\Requests\Request;
use App\Models\NotificationChannel;
use Illuminate\Support\Collection;

class TestClientRequest extends Request
{
    /** Sem canal conectado, 404. */
    public function authorize(): bool
    {
        return $this->channels()->isNotEmpty();
    }

    /**
     * Os canais do cliente da URL (`{user}`, resolvido pelo `User $user` do
     * controller), menos os que ainda não terminaram de conectar.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function channels(): Collection
    {
        return $this->route('user')->notificationChannels->reject(fn ($c) => $c->status === 'pending');
    }
}
