<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mailbox\MailThreads;
use PHPUnit\Framework\TestCase;

/**
 * E-mail 4b · Agrupar como o Gmail: pelo fio (seja de quem for) e, sem fio, o mesmo remetente com o
 * mesmo assunto normalizado até 7 dias; o mesmo assunto de remetentes diferentes não se junta.
 */
class MailThreadsTest extends TestCase
{
    private function item(int $uid, string $subject, string $from, string $date, array $over = []): array
    {
        return $over + ['uid' => $uid, 'subject' => $subject, 'from' => ['name' => null, 'address' => $from], 'date' => $date,
            'message_id' => "<m{$uid}@exemplo.invalid>", 'in_reply_to' => null, 'references' => []];
    }

    /** @return array<int, string> uid → thread */
    private function threads(array $items): array
    {
        return array_column(MailThreads::assign($items), 'thread', 'uid');
    }

    public function test_the_same_subject_and_sender_within_seven_days_join_even_without_references(): void
    {
        $t = $this->threads([
            $this->item(1, 'Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-20T09:00:00+00:00'),
            $this->item(2, 'RE:  alerta de SEGURANÇA ', 'No-Reply@accounts.google.invalid', '2026-10-24T09:00:00+00:00'),
            $this->item(3, 'Fwd: Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-27T09:00:00+00:00'),
            $this->item(4, 'Enc: Res: Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-27T10:00:00+00:00'),
        ]);
        $this->assertCount(1, array_unique($t));
    }

    public function test_the_same_subject_from_different_senders_does_not_join(): void
    {
        $t = $this->threads([
            $this->item(1, 'Orçamento', 'oficina@fornecedor.invalid', '2026-10-27T09:00:00+00:00'),
            $this->item(2, 'Orçamento', 'outra@fornecedor.invalid', '2026-10-27T10:00:00+00:00'),
            $this->item(3, 'Re: Orçamento', 'matilde@exemplo.pt', '2026-10-27T11:00:00+00:00'),
        ]);
        $this->assertCount(3, array_unique($t));
    }

    public function test_more_than_seven_days_apart_does_not_join(): void
    {
        $t = $this->threads([
            $this->item(1, 'Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-01T09:00:00+00:00'),
            $this->item(2, 'Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-08T09:00:01+00:00'),
        ]);
        $this->assertNotSame($t[1], $t[2]);
        $within = $this->threads([
            $this->item(1, 'Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-01T09:00:00+00:00'),
            $this->item(2, 'Alerta de segurança', 'no-reply@accounts.google.invalid', '2026-10-08T09:00:00+00:00'),
        ]);
        $this->assertSame($within[1], $within[2]); // exatamente 7 dias ainda junta
    }

    public function test_the_thread_still_joins_across_senders_and_subjects(): void
    {
        $t = $this->threads([
            $this->item(1, 'A sua nova viatura', 'matilde@exemplo.pt', '2026-10-01T09:00:00+00:00', ['message_id' => '<pos-venda@exemplo.pt>']),
            $this->item(2, 'Obrigada!', 'joana@cliente.invalid', '2026-10-25T09:00:00+00:00', ['in_reply_to' => '<pos-venda@exemplo.pt>']),
            $this->item(3, 'Outra pergunta', 'joao@cliente.invalid', '2026-10-26T09:00:00+00:00', ['references' => ['<pos-venda@exemplo.pt>', '<m2@exemplo.invalid>']]),
            $this->item(4, 'A sua nova viatura', 'joana@cliente.invalid', '2026-10-02T09:00:00+00:00'),
        ]);
        $this->assertSame($t[1], $t[2]);
        $this->assertSame($t[1], $t[3]);
        $this->assertNotSame($t[1], $t[4]); // o mesmo assunto, mas de outra pessoa e sem fio
    }

    public function test_empty_subjects_or_unknown_senders_do_not_join_by_subject(): void
    {
        $t = $this->threads([
            $this->item(1, '', 'a@exemplo.invalid', '2026-10-27T09:00:00+00:00'),
            $this->item(2, 'Re:', 'a@exemplo.invalid', '2026-10-27T10:00:00+00:00'),
            $this->item(3, 'Sem remetente', '', '2026-10-27T09:00:00+00:00'),
            $this->item(4, 'Sem remetente', '', '2026-10-27T10:00:00+00:00'),
        ]);
        $this->assertCount(4, array_unique($t));
    }
}
