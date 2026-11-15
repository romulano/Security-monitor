<?php

namespace App\Services\Security;

/**
 * O resultado de uma verificação. O valor é sempre curto (até 120 caracteres) e nunca carrega
 * o conteúdo da página, de um cookie ou de um arquivo: só o que prova o estado ("expira em 12
 * dias", "falta Secure").
 */
final class CheckResult
{
    public const OK = 'ok';

    public const WARNING = 'aviso';

    public const CRITICAL = 'critico';

    /** Não deu para verificar (site fora do ar, DNS sem resposta): não conta como problema nem como acerto. */
    public const UNTESTED = 'nao_testavel';

    public readonly string $value;

    public readonly string $subject;

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        string $value = '',
        string $subject = '',
    ) {
        $this->value = mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, 120);
        // Cabe na coluna security_findings.subject (120): nome de cookie e caminho nunca passam disso.
        $this->subject = mb_substr($subject, 0, 120);
    }

    public static function ok(string $id, string $value = '', string $subject = ''): self
    {
        return new self($id, self::OK, $value, $subject);
    }

    public static function warning(string $id, string $value, string $subject = ''): self
    {
        return new self($id, self::WARNING, $value, $subject);
    }

    public static function critical(string $id, string $value, string $subject = ''): self
    {
        return new self($id, self::CRITICAL, $value, $subject);
    }

    public static function untested(string $id, string $value = '', string $subject = ''): self
    {
        return new self($id, self::UNTESTED, $value, $subject);
    }

    /** Chave de uma ocorrência: a mesma verificação pode aparecer uma vez por cookie ou por caminho. */
    public function key(): string
    {
        return $this->id.'|'.$this->subject;
    }

    public function isProblem(): bool
    {
        return $this->status === self::WARNING || $this->status === self::CRITICAL;
    }
}
