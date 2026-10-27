<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/** O resultado de uma operação IMAP: o que se leu quando correu bem, o motivo quando não. */
final class ImapResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $sentFolder = null,
        public readonly ?int $unseen = null,
        public readonly ?int $uidvalidity = null,
        public readonly array $data = [], // E-mail 4: o que se leu (pastas, lista, mensagem)
    ) {}

    public static function ok(?string $sentFolder = null, ?int $unseen = null, ?int $uidvalidity = null): self
    {
        return new self(true, null, $sentFolder, $unseen, $uidvalidity);
    }

    public static function data(array $data, ?int $uidvalidity = null): self
    {
        return new self(true, null, null, null, $uidvalidity, $data);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
