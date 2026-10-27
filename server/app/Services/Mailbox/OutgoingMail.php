<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * Uma mensagem a enviar por uma caixa (uma a uma; o remetente é sempre o endereço da caixa). O
 * Message-ID, quando vem, é o que fica gravado (E-mail 2): assim as respostas encontram o fio.
 */
final class OutgoingMail
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $fromName = null,
        public readonly ?string $messageId = null,
    ) {}
}
