<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use Throwable;
use Webklex\PHPIMAP\Message;

/**
 * E-mail 4 · Ler uma mensagem (MIME) para o ecrã: o resumo da lista (só o cabeçalho), a mensagem
 * inteira (o texto, o HTML limpo por MailHtml e a lista dos anexos) e o conteúdo de um anexo, que
 * se lê ao vivo da caixa e nunca fica guardado.
 */
final class MailParser
{
    /** Os anexos que se podem "Ver" numa pré-visualização (o SVG não: pode ter scripts). */
    public const PREVIEWABLE = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** @return array{uid: int, subject: string, from: array{name: ?string, address: ?string}, date: ?string, seen: bool, flagged: bool, has_attachments: bool, message_id: ?string} */
    public static function summary(int $uid, string $header, array $flags): array
    {
        $m = self::load(rtrim($header) . "\r\n\r\n");
        $type = strtolower((string) ($m?->getHeader()?->get('content_type') ?? ''));
        $thread = $m ? self::thread(rtrim($header) . "\r\n\r\n") : ['in_reply_to' => null, 'references' => []];

        return [
            'uid' => $uid,
            'subject' => $m ? self::subject($m) : '',
            'from' => $m ? self::address($m->getFrom()->first()) : ['name' => null, 'address' => null],
            'date' => $m ? self::date($m) : null,
            'seen' => self::hasFlag($flags, '\\Seen'),
            'flagged' => self::hasFlag($flags, '\\Flagged'),
            // Pelo cabeçalho (a lista não lê o corpo): multipart/mixed ou um ficheiro sozinho (application/…).
            'has_attachments' => str_contains($type, 'multipart/mixed') || str_starts_with($type, 'application/'),
            'message_id' => $m ? self::id((string) $m->getMessageId()) : null,
            'in_reply_to' => $thread['in_reply_to'],
            'references' => $thread['references'],
        ];
    }

    /** O fio: o próprio Message-ID, o In-Reply-To e as References, com < >. @return array{message_id: ?string, in_reply_to: ?string, references: list<string>} */
    public static function thread(string $header): array
    {
        $m = self::load(str_contains($header, "\n\n") || str_contains($header, "\r\n\r\n") ? $header : rtrim($header) . "\r\n\r\n");
        if (! $m) {
            return ['message_id' => null, 'in_reply_to' => null, 'references' => []];
        }
        $refs = array_values(array_filter(array_map(fn ($r) => self::id((string) $r), (array) $m->getReferences()->toArray())));

        return ['message_id' => self::id((string) $m->getMessageId()), 'in_reply_to' => self::id((string) $m->getInReplyTo()), 'references' => $refs];
    }

    /** A mensagem inteira para o ecrã. */
    public static function full(string $raw, array $flags = []): ?array
    {
        $m = self::load($raw);
        if (! $m) {
            return null;
        }
        $attachments = [];
        $cid = [];
        foreach ($m->getAttachments()->values() as $i => $a) {
            $mime = strtolower((string) $a->content_type);
            if ($a->id && $a->disposition !== 'attachment') {
                $cid[trim((string) $a->id, '<>')] = ['mime' => $mime, 'content' => (string) $a->getContent()];
            }
            $attachments[] = ['index' => $i, 'name' => self::filename($a, $i), 'mime' => $mime, 'size' => strlen((string) $a->getContent()),
                'inline' => $a->disposition === 'inline' && $a->id !== null, 'previewable' => in_array($mime, self::PREVIEWABLE, true)];
        }
        $html = $m->hasHTMLBody() ? MailHtml::clean($m->getHTMLBody(), $cid) : ['html' => '', 'external_images' => 0];
        $thread = self::thread($raw);

        return [
            'message_id' => $thread['message_id'],
            'in_reply_to' => $thread['in_reply_to'],
            'references' => $thread['references'],
            'subject' => self::subject($m),
            'from' => self::address($m->getFrom()->first()),
            'to' => array_map(fn ($a) => self::address($a), $m->getTo()->all()),
            'cc' => array_map(fn ($a) => self::address($a), $m->getCc()->all()),
            'date' => self::date($m),
            'text' => $m->hasTextBody() ? $m->getTextBody() : null,
            'html' => $html['html'] !== '' ? $html['html'] : null,
            'external_images' => $html['external_images'],
            'seen' => self::hasFlag($flags, '\\Seen'),
            // Os anexos da lista: sem as imagens que só servem o HTML (as que entram por cid:).
            'attachments' => array_values(array_filter($attachments, fn ($a) => ! $a['inline'])),
        ];
    }

    /** O conteúdo de um anexo (pela posição na mensagem). @return array{name: string, mime: string, content: string}|null */
    public static function attachment(string $raw, int $index): ?array
    {
        $m = self::load($raw);
        $a = $m?->getAttachments()->values()->get($index);
        if (! $a) {
            return null;
        }

        return ['name' => self::filename($a, $index), 'mime' => strtolower((string) $a->content_type) ?: 'application/octet-stream', 'content' => (string) $a->getContent()];
    }

    private static function load(string $raw): ?Message
    {
        try {
            return Message::fromString($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private static function subject(Message $m): string
    {
        return self::decode((string) $m->getSubject());
    }

    /**
     * Os cabeçalhos com palavras codificadas (RFC 2047: =?UTF-8?Q?...?= e =?UTF-8?B?...?=), que o
     * leitor do pacote deixa como estão: o assunto, o nome de quem envia e o nome dos anexos.
     */
    public static function decode(string $value): string
    {
        $value = trim($value);
        if (! str_contains($value, '=?')) {
            return $value;
        }
        // Entre duas palavras codificadas seguidas, o espaço não conta (RFC 2047, 6.2).
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded === false || $decoded === '') {
            $decoded = mb_decode_mimeheader($value);
        }

        return trim($decoded);
    }

    private static function date(Message $m): ?string
    {
        try {
            $d = $m->getDate()->first();

            return $d ? \Carbon\Carbon::parse((string) $d)->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{name: ?string, address: ?string} */
    private static function address($a): array
    {
        $a = is_object($a) ? $a : null;

        return ['name' => $a?->personal ? self::decode((string) $a->personal) : null, 'address' => $a?->mail ? strtolower((string) $a->mail) : null];
    }

    private static function id(string $id): ?string
    {
        $id = trim($id);

        return $id === '' ? null : '<' . trim($id, '<>') . '>';
    }

    private static function hasFlag(array $flags, string $flag): bool
    {
        foreach ($flags as $f) {
            if (strcasecmp((string) $f, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function filename($a, int $i): string
    {
        $name = self::decode((string) ($a->name ?: $a->filename));
        $name = preg_replace('/[\x00-\x1f\/\\\\]+/', '_', $name) ?? '';

        return $name !== '' ? mb_substr($name, 0, 150) : 'anexo-' . ($i + 1);
    }
}
