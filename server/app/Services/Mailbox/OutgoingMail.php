<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * Uma mensagem a enviar por uma caixa (uma a uma; o remetente é sempre o endereço da caixa). O
 * Message-ID, quando vem, é o que fica gravado (E-mail 2): assim as respostas encontram o fio.
 * E-mail 5: vários destinatários ("a@x.pt, b@y.pt"), o Cc e o fio das respostas (In-Reply-To e
 * References).
 */
final class OutgoingMail
{
    /** @param list<string> $cc @param list<string> $references */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $fromName = null,
        public readonly ?string $messageId = null,
        public readonly array $cc = [],
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
    ) {}

    /** @return list<string> os destinatários de "Para", um a um */
    public function toList(): array
    {
        return self::split($this->to);
    }

    /** @return list<string> */
    public static function split(string $addresses): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]/', $addresses) ?: [])));
    }
}
