<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/** O resultado de um envio: o Message-ID quando correu bem, o motivo quando não. */
final class MailboxSendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId,
        public readonly ?string $error,
        public readonly ?string $raw = null, // a mensagem MIME tal como saiu (para a cópia em Enviados, E-mail 3)
    ) {}

    public static function sent(?string $messageId, ?string $raw = null): self
    {
        return new self(true, $messageId, null, $raw);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
