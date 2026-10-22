<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingRequest;

/**
 * Os textos que o cliente aceita na página de envio (e que o site mostra, se usar a API),
 * montados com os dados da empresa: o aviso de proteção de dados (quem trata, para quê, quem
 * recebe, quanto tempo e os direitos), um para o financiamento e outro para a retoma, e o
 * consentimento para a cópia do documento de identificação, com a regra do Cartão de Cidadão
 * (Lei n.º 7/2007, art. 5.º). Guardam-se tal como foram mostrados, com a versão e o nome escrito.
 */
final class FinancingNotice
{
    public static function version(): string
    {
        return (string) config('financing.notice_version');
    }

    /** @return array{title: string, paragraphs: string[]} */
    public static function privacy(Company $company, string $purpose = FinancingRequest::FINANCING): array
    {
        $name = (string) ($company->fiscal_name ?: $company->trade_name);
        $who = $name . ($company->nipc ? " (NIPC {$company->nipc})" : '');
        $address = trim(implode(', ', array_filter([$company->address, $company->postal_code])));
        $contact = implode(' ou ', array_filter([$company->email, $company->phone ?: $company->mobile]));
        $days = RetentionDays::for($company);
        $rights = 'Os seus direitos: pode pedir o acesso, a retificação ou o apagamento dos seus dados e opor-se ao tratamento'
            . ($contact !== '' ? ", através de {$contact}" : '') . '. Pode também apresentar reclamação à Comissão Nacional de Proteção de Dados (CNPD).';
        $whoLine = "Quem trata os seus dados: {$who}" . ($address !== '' ? ", {$address}" : '') . '.';

        if ($purpose === FinancingRequest::TRADE_IN) {
            return [
                'title' => 'Proteção dos seus dados',
                'paragraphs' => [
                    $whoLine,
                    'Para quê: avaliar a viatura que propõe para retoma e preparar a proposta.',
                    "Quem recebe as fotografias e o DUA: {$name}. O DUA tem o nome e a morada do proprietário e só o veem as pessoas que tratam do financiamento.",
                    "Quanto tempo ficam guardados: os ficheiros são apagados automaticamente {$days} dias depois do último envio, ou antes, se o pedir.",
                    $rights,
                ],
            ];
        }

        return [
            'title' => 'Proteção dos seus dados',
            'paragraphs' => [
                $whoLine,
                'Para quê: analisar o pedido de financiamento da viatura e apresentá-lo à instituição de crédito.',
                "Quem recebe os documentos e os dados que preencher: {$name} e a instituição de crédito a quem o pedido for apresentado.",
                "Quanto tempo ficam guardados: os documentos e os dados são apagados automaticamente {$days} dias depois do último envio, ou antes, se o pedir.",
                $rights,
            ],
        ];
    }

    public static function privacyText(Company $company, string $purpose = FinancingRequest::FINANCING): string
    {
        $n = self::privacy($company, $purpose);

        return $n['title'] . "\n\n" . implode("\n", $n['paragraphs']);
    }

    /** O consentimento para a cópia do documento de identificação (com a regra do CC). */
    public static function idCopy(Company $company): string
    {
        $name = (string) ($company->fiscal_name ?: $company->trade_name);

        return "Autorizo {$name} a guardar uma cópia do meu documento de identificação, só para a análise do pedido de financiamento. "
            . 'Se for o Cartão de Cidadão, este consentimento é dado nos termos do artigo 5.º da Lei n.º 7/2007, de 5 de fevereiro.';
    }
}
