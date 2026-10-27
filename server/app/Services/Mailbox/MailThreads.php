<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

/**
 * E-mail 4 · Agrupar as mensagens de uma página por conversa, como no Gmail. Juntam-se:
 *  · as que estão ligadas pelo fio (o Message-ID de uma aparece no In-Reply-To ou nas References de
 *    outra), sejam de quem forem;
 *  · E-mail 4b: as do mesmo remetente com o mesmo assunto normalizado (sem "Re:", "RE:", "Fwd:",
 *    "Enc:", "Res:"… nem espaços a mais, sem contar maiúsculas), recebidas até 7 dias umas das outras,
 *    mesmo sem fio (por exemplo, três "Alerta de segurança" da Google).
 * O mesmo assunto de remetentes diferentes não se junta, a não ser pelo fio. Cada item recebe
 * "thread": o mesmo valor para as mensagens da mesma conversa (que continuam a abrir-se uma a uma).
 */
final class MailThreads
{
    public const SAME_SENDER_DAYS = 7;

    /**
     * @param  list<array{uid: int, subject: string, message_id: ?string, in_reply_to?: ?string, references?: list<string>, from?: array{address: ?string}, date?: ?string}>  $items
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

        $bySubjectAndSender = [];
        foreach ($items as $item) {
            $me = 'uid:' . $item['uid'];
            $find($me);
            foreach (array_filter([$item['message_id'] ?? null, $item['in_reply_to'] ?? null, ...($item['references'] ?? [])]) as $id) {
                $union($me, 'id:' . strtolower((string) $id));
            }
            $key = self::subjectKey((string) $item['subject']);
            $sender = strtolower(trim((string) ($item['from']['address'] ?? '')));
            if ($key !== '' && $sender !== '') {
                $bySubjectAndSender[$key . "\n" . $sender][] = ['node' => $me, 'at' => self::timestamp($item['date'] ?? null)];
            }
        }
        // Sem fio: o mesmo remetente e o mesmo assunto, até 7 dias entre mensagens seguidas.
        foreach ($bySubjectAndSender as $group) {
            usort($group, fn ($a, $b) => $a['at'] <=> $b['at']);
            for ($i = 1, $n = count($group); $i < $n; $i++) {
                if ($group[$i]['at'] !== null && $group[$i - 1]['at'] !== null && $group[$i]['at'] - $group[$i - 1]['at'] <= self::SAME_SENDER_DAYS * 86400) {
                    $union($group[$i - 1]['node'], $group[$i]['node']);
                }
            }
        }

        return array_map(fn ($item) => $item + ['thread' => substr(md5($find('uid:' . $item['uid'])), 0, 12)], $items);
    }

    private const PREFIX = '/^\s*((re|res|fw|fwd|enc|tr|aw|wg|rv)\s*(\[\d+\])?\s*:\s*)+/iu';

    /** O assunto sem os prefixos de resposta e reencaminhamento, sem espaços a mais e em minúsculas. */
    public static function subjectKey(string $subject): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::PREFIX, '', $subject))));
    }

    private static function timestamp(?string $date): ?int
    {
        $t = $date ? strtotime($date) : false;

        return $t === false ? null : $t;
    }
}
