<?php

namespace App\Services\Alerts\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * O código do link de conexão (t.me/<bot>?start=<código>). Vale uma vez só e
 * por pouco tempo; o cache guarda só o hash, ligado ao cliente que o gerou.
 * Quem manda o /start com o código conecta aquela conversa aos alertas dele.
 */
class ConnectionLinks
{
    public const PATTERN = '/^[A-Za-z0-9]{32}$/';

    /** `$target`: onde o canal recebe, na empresa (CompanyChannels::pack); vazio = o canal pessoal. */
    public function create(int $userId, array $target = []): string
    {
        $code = Str::random(32);
        $ttl = now()->addMinutes((int) config('secmon.alerts.telegram.link_ttl_minutes', 30));

        // O pessoal guarda só o id, na chave de sempre: no deploy, o secmon:telegram-updates do supervisor velho segue de pé
        // depois do app novo e faz (int) no que acha (um array viraria a conta 1). O da empresa vai noutra chave, que a
        // versão de antes não lê (para ela, o link expirou).
        $target === []
            ? Cache::put($this->key($code), $userId, $ttl)
            : Cache::put($this->companyKey($code), ['user' => $userId, 'target' => $target], $ttl);

        return $code;
    }

    /** O link ainda espera o /start (não foi usado nem expirou). */
    public function pending(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1 && (Cache::has($this->companyKey($code)) || Cache::has($this->key($code)));
    }

    /**
     * Usa o código: devolve o cliente dono dele e onde o canal recebe, ou nulo se expirou ou já foi usado. O código de
     * antes desta versão guardava só o id (canal pessoal).
     *
     * @return array{user: int, target: array}|null
     */
    public function consume(string $code): ?array
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }

        $link = Cache::pull($this->companyKey($code)) ?? Cache::pull($this->key($code));

        return match (true) {
            is_array($link) && isset($link['user']) => ['user' => (int) $link['user'], 'target' => is_array($link['target'] ?? null) ? $link['target'] : []],
            is_numeric($link) && (int) $link > 0 => ['user' => (int) $link, 'target' => []],
            default => null,
        };
    }

    private function key(string $code): string
    {
        return 'telegram-link:'.hash('sha256', $code);
    }

    private function companyKey(string $code): string
    {
        return 'telegram-link-company:'.hash('sha256', $code);
    }
}
