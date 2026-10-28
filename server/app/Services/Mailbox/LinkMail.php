<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\CarSale;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FinancingLink;
use App\Models\FinancingRequest;

/**
 * E-mail 9 · O link de financiamento (pedido de documentos) e o de retoma enviados pela caixa da
 * própria pessoa. Prepara a mensagem (o endereço, o assunto e um texto curto com o link e o prazo; a
 * assinatura junta-a o MailComposer) e esconde o link na cópia guardada: os links guardam só o hash e
 * o token cifrado, por isso em mail_messages e na "Conversa" o link aparece como "[link enviado,
 * oculto por segurança]". Só a mensagem que sai para o cliente e a cópia em Enviados o levam.
 */
class LinkMail
{
    public const FINANCING = 'financing';
    public const TRADE_IN = 'trade_in';
    public const KINDS = [self::FINANCING, self::TRADE_IN];
    public const HIDDEN = '[link enviado, oculto por segurança]';

    /** O link desta empresa e deste tipo (404 se for de outra empresa ou do outro tipo). */
    public function resolve(int $companyId, string $kind, int $linkId): FinancingLink
    {
        $link = FinancingLink::with(['request', 'lead'])->where('company_id', $companyId)->findOrFail($linkId);
        $tradeIn = (bool) $link->request?->isTradeIn();
        abort_if(! $link->request || $tradeIn !== ($kind === self::TRADE_IN), 404);

        return $link;
    }

    /**
     * Para quem e o quê: o endereço (o do titular, senão o da lead no primeiro titular), o nome, o
     * consentimento de contacto da lead, o assunto, o texto e as ligações (lead, link e cliente).
     * @return array{to: ?string, name: ?string, consent: bool, subject: string, body: string, expires_at: string, open: bool, links: array<string, int|null>}
     */
    public function context(FinancingLink $link, string $kind): array
    {
        $lead = $link->lead;
        $to = $link->email ?: ((int) $link->holder <= 1 ? $lead?->email : null);
        $name = $link->customer_name ?: ((int) $link->holder <= 1 ? $lead?->name : null);
        $company = Company::find($link->company_id);
        $companyName = (string) ($company?->trade_name ?: $company?->fiscal_name);

        return [
            'to' => $to ?: null,
            'name' => $name ?: null,
            // O mesmo do E-mail 5: o contacto da lead (o formulário do site regista quando foi dado).
            'consent' => $lead === null || $lead->consent_contact_at !== null,
            'subject' => ($kind === self::FINANCING ? 'Documentos para o financiamento' : 'Avaliação da sua retoma') . ($companyName !== '' ? " · {$companyName}" : ''),
            'body' => $link->isOpen() ? $this->body($kind, $link->url(), $link->expires_at->format('d/m/Y'), $name ?: null) : '',
            'expires_at' => $link->expires_at->toIso8601String(),
            'open' => $link->isOpen(),
            'links' => ['car_lead_id' => $link->car_lead_id, 'financing_link_id' => $link->id, 'customer_id' => $this->customerId($link, $to)],
        ];
    }

    /** O texto curto (HTML do editor): a saudação, o pedido, o link, o prazo e o fecho. */
    public function body(string $kind, string $url, string $until, ?string $name): string
    {
        $hello = '<p>Olá' . ($name ? ', ' . e($name) : '') . ',</p>';
        $link = '<p><a href="' . e($url) . '">' . e($url) . '</a></p>';
        if ($kind === self::FINANCING) {
            return $hello
                . '<p>Para avançarmos com o pedido de financiamento, envie-nos os documentos pedidos através deste link seguro:</p>'
                . $link
                . '<p>O link é pessoal e válido até ' . $until . '. Por favor, não o partilhe com ninguém.</p>'
                . '<p>Com os melhores cumprimentos,</p>';
        }

        return $hello
            . '<p>Obrigado pelo seu pedido. Para avaliarmos a sua retoma, envie-nos as fotografias da viatura através deste link seguro:</p>'
            . $link
            . '<p>O link é válido até ' . $until . '.</p>'
            . '<p>Com os melhores cumprimentos,</p>';
    }

    /**
     * A cópia guardada: o link (e o token sozinho, se aparecer) trocado pelo aviso. No HTML, a ligação
     * para o link passa a texto (sem href), para não ficar uma ligação partida na "Conversa".
     */
    public static function hide(string $content, FinancingLink $link): string
    {
        $url = $link->url();
        $token = $link->token();
        $content = preg_replace_callback('#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is',
            fn ($m) => str_contains(html_entity_decode($m[1]), $token) ? '<em>' . self::HIDDEN . '</em>' : $m[0], $content) ?? $content;

        return str_replace([e($url), $url, $token], [self::HIDDEN, self::HIDDEN, self::HIDDEN], $content);
    }

    /** O cliente da lead, se existir: o da venda dessa lead, senão o cliente da empresa com o mesmo endereço. */
    private function customerId(FinancingLink $link, ?string $to): ?int
    {
        if ($link->car_lead_id && ($id = CarSale::where('company_id', $link->company_id)->where('lead_id', $link->car_lead_id)->whereNotNull('customer_id')->value('customer_id'))) {
            return (int) $id;
        }
        $email = $to ?: $link->lead?->email;

        return $email ? (Customer::where('company_id', $link->company_id)->where('email', $email)->value('id') ?: null) : null;
    }

    public static function kindOf(FinancingLink $link): string
    {
        return $link->request?->purpose === FinancingRequest::TRADE_IN ? self::TRADE_IN : self::FINANCING;
    }
}
