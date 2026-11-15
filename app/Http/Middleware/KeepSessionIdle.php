<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A consulta periódica dos testes de segurança (a página da execução e a faixa)
 * não conta como uso da sessão (item 6 do Blue Team). Ela lê a sessão, como toda
 * rota, mas não a grava: o `last_activity` não anda, e a sessão expira depois de 4
 * horas sem uso, como a /privacidade promete, mesmo com uma aba aberta consultando.
 */
class KeepSessionIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $handler = $session->getHandler();
        $request->attributes->set(self::class, $handler);
        $session->setHandler(new class($handler) implements SessionHandlerInterface
        {
            public function __construct(private SessionHandlerInterface $inner) {}

            public function open(string $path, string $name): bool
            {
                return $this->inner->open($path, $name);
            }

            public function close(): bool
            {
                return $this->inner->close();
            }

            public function read(string $id): string|false
            {
                return $this->inner->read($id);
            }

            /** A gravação do fim do pedido, que renovaria a sessão: não acontece. */
            public function write(string $id, string $data): bool
            {
                return true;
            }

            public function destroy(string $id): bool
            {
                return $this->inner->destroy($id);
            }

            public function gc(int $max_lifetime): int|false
            {
                return $this->inner->gc($max_lifetime);
            }
        });

        return $next($request);
    }

    /** O handler de verdade volta depois da resposta, para o processo que atende outro pedido (como nos testes). */
    public function terminate(Request $request, Response $response): void
    {
        $handler = $request->attributes->get(self::class);

        if ($handler instanceof SessionHandlerInterface && $request->hasSession()) {
            $request->session()->setHandler($handler);
        }
    }
}
