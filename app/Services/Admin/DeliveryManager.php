<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\NotificationDelivery;
use App\Services\Alerts\Notifier;

/** Admin → Alertas → Entregas: o log de envios, o payload de cada um e o reenvio. */
class DeliveryManager
{
    /**
     * @param  array{status?: ?string, tipo?: ?string, periodo?: ?string, q?: ?string}  $filters
     * @param  ?int  $payloadId  o envio cujo payload abre na página
     */
    public function page(array $filters, ?int $payloadId): array
    {
        $since = match ($filters['periodo'] ?? '7d') {
            '24h' => now()->subDay(), '30d' => now()->subDays(30), default => now()->subDays(7)
        };

        $base = NotificationDelivery::query()->where('created_at', '>=', $since);

        return [
            'deliveries' => (clone $base)
                ->with(['channel', 'user.alertProfile'])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['tipo'] ?? null, fn ($q, $k) => $q->where('kind', $k))
                ->when($filters['q'] ?? null, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$s}%")
                    ->orWhere('email', 'ilike', "%{$s}%")
                    ->orWhereHas('alertProfiles', fn ($p) => $p->where('company_name', 'ilike', "%{$s}%"))))
                ->latest()->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'totals' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'filters' => $filters + ['periodo' => '7d'],
            'payload' => $payloadId ? NotificationDelivery::query()->find($payloadId) : null,
        ];
    }

    /** Põe de novo na fila um envio que falhou. */
    public function retry(NotificationDelivery $delivery): void
    {
        if ($delivery->status !== 'failed') {
            throw new ActionRefused('Só envios que falharam podem ser reenviados.');
        }

        // O histórico guarda o código da reautenticação como pontos, e ele valia 10 minutos: quem pediu, pede outro.
        if ($delivery->kind === 'code') {
            throw new ActionRefused('Código de confirmação não é reenviado: quem pediu pede outro.');
        }

        if ($delivery->channel->status !== 'active' && $delivery->kind !== 'test') {
            throw new ActionRefused('O canal do cliente precisa ser reconectado antes de reenviar.');
        }

        $delivery->forceFill(['status' => 'queued', 'error' => null])->save();
        Notifier::dispatch($delivery);
    }
}
