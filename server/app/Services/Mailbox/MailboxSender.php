<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Jobs\SendMailboxMessageJob;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * E-mail 2 · Enviar uma mensagem pela caixa de correio de uma pessoa: verifica o limite da caixa,
 * grava a mensagem (com o Message-ID já gerado e as ligações a cliente, venda, lead ou relatório)
 * e põe o envio na fila (SendMailboxMessageJob). O consentimento de contacto verifica-o quem chama,
 * como no envio de hoje.
 */
class MailboxSender
{
    /** O motivo por que esta caixa não pode enviar agora (null se pode). */
    public function blockedReason(Mailbox $mailbox): ?string
    {
        if ($mailbox->status !== Mailbox::STATUS_ACTIVE) {
            return 'Teste primeiro a sua caixa de correio no Perfil (Testar envio).';
        }
        $sent = fn ($since) => MailMessage::where('mailbox_id', $mailbox->id)->where('direction', MailMessage::OUT)->where('created_at', '>=', $since)->count();
        if ($sent(now()->subHour()) >= (int) config('mailboxes.limits.per_hour')) {
            return 'A sua caixa chegou ao limite de ' . config('mailboxes.limits.per_hour') . ' envios por hora. Tente mais tarde.';
        }
        if ($sent(now()->subDay()) >= (int) config('mailboxes.limits.per_day')) {
            return 'A sua caixa chegou ao limite de ' . config('mailboxes.limits.per_day') . ' envios por dia. Tente amanhã.';
        }

        return null;
    }

    /**
     * @param  array{customer_id?: ?int, car_sale_id?: ?int, car_lead_id?: ?int, satisfaction_report_id?: ?int}  $links
     * @param  array{cc?: list<string>, in_reply_to?: ?string, references?: list<string>}  $thread  E-mail 5: o Cc e o fio das respostas
     * @param  ?\Illuminate\Support\Collection  $uploads  E-mail 5b: os anexos (MailAttachments::forSend)
     * @param  ?array{text: string, html: ?string}  $secret  E-mail 9: o texto completo (com o link), cifrado só até ao envio
     */
    public function queue(Mailbox $mailbox, User $sender, string $to, string $subject, string $text, ?string $html, array $links = [], array $thread = [], ?\Illuminate\Support\Collection $uploads = null, ?array $secret = null): MailMessage
    {
        $domain = Str::after($mailbox->email, '@') ?: 'xplendor.pt';
        $message = MailMessage::create([
            'company_id' => $mailbox->company_id,
            'mailbox_id' => $mailbox->id,
            'user_id' => $sender->id,
            'direction' => MailMessage::OUT,
            'message_id' => '<' . Str::uuid() . '@' . $domain . '>',
            'from_address' => $mailbox->email,
            'to_address' => $to,
            'cc_address' => ($thread['cc'] ?? []) ? implode(', ', $thread['cc']) : null,
            'in_reply_to' => $thread['in_reply_to'] ?? null,
            'references' => ($thread['references'] ?? []) ? implode(' ', $thread['references']) : null,
            'subject' => $subject,
            'body_text' => $text,
            'body_html' => $html,
            'status' => MailMessage::STATUS_QUEUED,
            'outgoing_secret' => $secret,
        ] + array_intersect_key($links, array_flip(['customer_id', 'car_sale_id', 'car_lead_id', 'satisfaction_report_id', 'financing_link_id'])));
        if ($uploads && $uploads->isNotEmpty()) {
            app(MailAttachments::class)->attach($message, $uploads); // E-mail 5b: antes de ir para a fila
        }
        SendMailboxMessageJob::dispatch($message->id, (string) $sender->name);

        return $message;
    }
}
