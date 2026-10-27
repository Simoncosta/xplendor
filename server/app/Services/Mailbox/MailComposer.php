<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailMessage;
use App\Models\User;
use Carbon\Carbon;

/**
 * E-mail 5 · Escrever e responder pela caixa de correio da pessoa: "Escrever" (mensagem nova),
 * "Responder" (no mesmo fio: In-Reply-To e References, com o original citado por baixo, "Em <data>,
 * <nome> escreveu:") e "Escrever ao cliente" (na ficha do cliente e na venda: fica ligado ao cliente,
 * à venda e à lead). A assinatura da caixa vai no fim. O consentimento de contacto é obrigatório para
 * escrever a um cliente final (o da ficha, o da venda, ou um endereço que seja de um cliente da
 * empresa), exceto ao responder no mesmo fio a uma mensagem que o próprio cliente enviou. Sem anexos na versão 1. Os limites, a cópia em Enviados e, em dev, o envio só para a
 * própria caixa vêm do MailboxSender, do job e do DevRecipientGuard.
 */
class MailComposer
{
    public const MAX_RECIPIENTS = 10;

    public function __construct(private readonly MailboxSender $sender, private readonly MailboxInbox $inbox) {}

    /**
     * Para quem escrever a partir da ficha do cliente ou da venda: o endereço, o nome, o consentimento e
     * as ligações. @return array{ok: bool, error?: string, to?: ?string, name?: ?string, consent?: bool, links?: array}
     */
    public function context(int $companyId, ?int $customerId, ?int $carId): array
    {
        if ($carId) {
            $car = Car::with(['sale.customer'])->where('company_id', $companyId)->find($carId);
            if (! $car || ! $car->sale) {
                return ['ok' => false, 'error' => 'Esta viatura ainda não tem uma venda registada.'];
            }
            $sale = $car->sale;

            return ['ok' => true, 'to' => $sale->customer?->email ?: $sale->buyer_email, 'name' => $sale->customer?->name ?: $sale->buyer_name,
                'consent' => (bool) $sale->contact_consent,
                'links' => ['customer_id' => $sale->customer_id, 'car_sale_id' => $sale->id, 'car_lead_id' => $sale->lead_id]];
        }
        if ($customerId) {
            $customer = Customer::where('company_id', $companyId)->find($customerId);
            if (! $customer) {
                return ['ok' => false, 'error' => 'Cliente não encontrado.'];
            }

            return ['ok' => true, 'to' => $customer->email, 'name' => $customer->name, 'consent' => (bool) $customer->contact_consent,
                'links' => ['customer_id' => $customer->id]];
        }

        return ['ok' => true, 'to' => null, 'name' => null, 'consent' => true, 'links' => []];
    }

    /**
     * @param  array{to: string, cc?: ?string, subject: string, body: string, reply_to?: ?array{folder: string, uid: int}, customer_id?: ?int, car_id?: ?int, attachments?: list<string>}  $input
     * @return array{ok: bool, error?: string, message?: MailMessage}
     */
    public function send(Mailbox $mailbox, User $user, array $input): array
    {
        if ($reason = $this->sender->blockedReason($mailbox)) {
            return ['ok' => false, 'error' => $reason];
        }
        $to = OutgoingMail::split((string) $input['to']);
        $cc = OutgoingMail::split((string) ($input['cc'] ?? ''));
        if (! $to) {
            return ['ok' => false, 'error' => 'Indique pelo menos um destinatário.'];
        }
        if (count($to) + count($cc) > self::MAX_RECIPIENTS) {
            return ['ok' => false, 'error' => 'No máximo ' . self::MAX_RECIPIENTS . ' destinatários por mensagem (Para e Cc).'];
        }
        foreach ([...$to, ...$cc] as $address) {
            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => "O endereço \"{$address}\" não é válido."];
            }
        }

        // A ficha do cliente ou a venda: o consentimento e as ligações.
        $context = $this->context($mailbox->company_id, $input['customer_id'] ?? null, $input['car_id'] ?? null);
        if (! $context['ok']) {
            return $context;
        }
        // A resposta: o fio e o original citado por baixo.
        $thread = ['cc' => $cc];
        $quoteText = '';
        $quoteHtml = '';
        $links = $context['links'];
        $repliedSender = null;
        if (! empty($input['reply_to'])) {
            $original = $this->inbox->message($mailbox, (string) $input['reply_to']['folder'], (int) $input['reply_to']['uid']);
            if (! $original['ok']) {
                return ['ok' => false, 'error' => 'A mensagem a que responde já não existe na caixa.'];
            }
            $o = $original['message'];
            if ($o['message_id']) {
                $thread['in_reply_to'] = $o['message_id'];
                $thread['references'] = array_slice(array_values(array_unique([...$o['references'], $o['message_id']])), -20);
            }
            [$quoteText, $quoteHtml] = $this->quote($o);
            $links['customer_id'] ??= $o['customer']['id'] ?? null; // responder a uma mensagem ligada mantém o cliente
            $repliedSender = strtolower(trim((string) ($o['from']['address'] ?? ''))) ?: null;
        }

        // O consentimento: responder no mesmo fio a quem escreveu é atender um pedido dele (não precisa);
        // escrever-lhe de novo, ou a outro cliente no Para ou no Cc, precisa.
        if (! $context['consent'] && strtolower((string) $context['to']) !== $repliedSender) {
            return ['ok' => false, 'error' => 'O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe.'];
        }
        $addresses = array_values(array_diff(array_map('strtolower', [...$to, ...$cc]), [$repliedSender]));
        $refused = $addresses ? (Customer::where('company_id', $mailbox->company_id)->where('contact_consent', false)->whereIn('email', $addresses)->value('email')
            ?? CarSale::where('company_id', $mailbox->company_id)->whereNull('customer_id')->where('contact_consent', false)->whereIn('buyer_email', $addresses)->value('buyer_email')) : null;
        if ($refused) {
            return ['ok' => false, 'error' => "O cliente {$refused} não deu consentimento para contacto (RGPD): não se pode escrever-lhe."];
        }

        // E-mail 5b: os anexos (da própria caixa, por enviar, até 10 e 20 MB no total).
        $files = app(MailAttachments::class)->forSend($mailbox, $user, (array) ($input['attachments'] ?? []));
        if (! $files['ok']) {
            return ['ok' => false, 'error' => $files['error']];
        }

        [$text, $html] = $this->body((string) $input['body'], (string) $mailbox->signature);
        $message = $this->sender->queue($mailbox, $user, implode(', ', $to), mb_substr(trim((string) $input['subject']), 0, 500),
            $text . $quoteText, $html . $quoteHtml, array_filter($links, fn ($v) => $v !== null), $thread, $files['uploads']);

        return ['ok' => true, 'message' => $message->fresh()];
    }

    /** O corpo escrito (HTML do editor, limpo) e a versão em texto, com a assinatura no fim. @return array{0: string, 1: string} */
    public function body(string $html, string $signature): array
    {
        $clean = OutgoingHtml::clean($html);
        $text = OutgoingHtml::toText($clean);
        $signature = trim($signature);
        if ($signature !== '') {
            $text .= "\n\n-- \n" . $signature;
            $clean .= '<p>-- <br>' . self::lines($signature) . '</p>';
        }

        return [$text, $clean];
    }

    /** Texto simples para HTML: escapado e com as quebras de linha em <br>. */
    private static function lines(string $text): string
    {
        return str_replace(["\r\n", "\n"], '<br>', e($text));
    }

    /** "Em <data>, <nome> escreveu:" e o original citado (texto com "> " e HTML em blockquote). @return array{0: string, 1: string} */
    private function quote(array $o): array
    {
        $date = $o['date'] ? Carbon::parse($o['date'])->setTimezone('Europe/Lisbon')->locale('pt_PT') : null;
        $when = $date ? $date->translatedFormat('j \d\e F \d\e Y') . ', às ' . $date->format('H:i') : 'data desconhecida';
        $who = trim((string) ($o['from']['name'] ?? '')) ?: (string) ($o['from']['address'] ?? '');
        $address = (string) ($o['from']['address'] ?? '');
        $header = "Em {$when}, {$who}" . ($address && $address !== $who ? " <{$address}>" : '') . ' escreveu:';
        $original = $o['text'] ?: OutgoingHtml::toText((string) $o['html']);
        $quoted = implode("\n", array_map(fn ($l) => '> ' . $l, explode("\n", rtrim($original))));

        return ["\n\n{$header}\n{$quoted}",
            '<p>' . e($header) . '</p><blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">' . self::lines(rtrim($original)) . '</blockquote>'];
    }
}
