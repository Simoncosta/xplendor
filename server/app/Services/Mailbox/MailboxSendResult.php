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
        public readonly bool $delivered, // false quando foi para o Mailpit (dev) em vez do servidor da caixa
    ) {}

    public static function sent(?string $messageId, bool $delivered = true): self
    {
        return new self(true, $messageId, null, $delivered);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error, false);
    }
}
