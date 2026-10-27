<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/** Uma mensagem a enviar por uma caixa (uma a uma; o remetente é sempre o endereço da caixa). */
final class OutgoingMail
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $fromName = null,
    ) {}
}
