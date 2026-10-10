<?php

declare(strict_types=1);

namespace App\Access;

/** ACL: o resultado de Access::can. Recusado leva sempre o motivo em português, para o ecrã mostrar tal como vem. */
final class Decision
{
    public const TENANT = 'empresa';
    public const MODULE = 'modulo';
    public const IMPERSONATION = 'impersonacao';
    public const PLATFORM = 'plataforma';
    public const CLIENT_DECISION = 'decisao_do_cliente';
    public const PROFILE = 'perfil';
    public const CEILING = 'teto_da_agencia';
    public const ROOT = 'root';
    public const UNKNOWN = 'permissao_desconhecida';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason, string $code): self
    {
        return new self(false, $reason, $code);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** @return array{allowed: bool, reason: ?string, code: ?string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason, 'code' => $this->code];
    }
}
