<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * E-mail 4 · Agrupar as mensagens de uma página por conversa, como no Gmail: juntam-se as que estão
 * ligadas pelo fio (o Message-ID de uma aparece no In-Reply-To ou nas References de outra) e, sem fio,
 * as respostas e reencaminhamentos com o mesmo assunto ("Re:", "RES:", "Fwd:", "ENC:"…). Cada item
 * recebe "thread": o mesmo valor para as mensagens da mesma conversa.
 */
final class MailThreads
{
    /**
     * @param  list<array{uid: int, subject: string, message_id: ?string, in_reply_to?: ?string, references?: list<string>}>  $items
     * @return list<array>
     */
    public static function assign(array $items): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $bySubject = [];
        foreach ($items as $item) {
            $me = 'uid:' . $item['uid'];
            $find($me);
            foreach (array_filter([$item['message_id'] ?? null, $item['in_reply_to'] ?? null, ...($item['references'] ?? [])]) as $id) {
                $union($me, 'id:' . strtolower((string) $id));
            }
            $key = self::subjectKey((string) $item['subject']);
            if ($key !== '') {
                $bySubject[$key][] = ['node' => $me, 'reply' => self::isReply((string) $item['subject'])];
            }
        }
        // Sem fio: o mesmo assunto, quando pelo menos uma delas é resposta ou reencaminhamento.
        foreach ($bySubject as $group) {
            if (count($group) > 1 && in_array(true, array_column($group, 'reply'), true)) {
                foreach ($group as $g) {
                    $union($group[0]['node'], $g['node']);
                }
            }
        }

        return array_map(fn ($item) => $item + ['thread' => substr(md5($find('uid:' . $item['uid'])), 0, 12)], $items);
    }

    private const PREFIX = '/^\s*((re|res|fw|fwd|enc|tr|aw|wg|rv)\s*(\[\d+\])?\s*:\s*)+/iu';

    public static function subjectKey(string $subject): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::PREFIX, '', $subject))));
    }

    private static function isReply(string $subject): bool
    {
        return (bool) preg_match(self::PREFIX, $subject);
    }
}
