<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;

/**
 * Os textos que o cliente aceita na página de envio, montados com os dados da empresa: o aviso
 * de proteção de dados (quem trata, para quê, quem recebe, quanto tempo e os direitos) e o
 * consentimento expresso para a cópia do Cartão de Cidadão (Lei n.º 7/2007, art. 5.º). Guardam-se
 * tal como foram mostrados (FinancingConsent), com a versão.
 */
final class FinancingNotice
{
    public static function version(): string
    {
        return (string) config('financing.notice_version');
    }

    /** @return array{title: string, paragraphs: string[]} */
    public static function privacy(Company $company): array
    {
        $name = (string) ($company->fiscal_name ?: $company->trade_name);
        $who = $name . ($company->nipc ? " (NIPC {$company->nipc})" : '');
        $address = trim(implode(', ', array_filter([$company->address, $company->postal_code])));
        $contact = implode(' ou ', array_filter([$company->email, $company->phone ?: $company->mobile]));
        $days = RetentionDays::for($company);

        return [
            'title' => 'Proteção dos seus dados',
            'paragraphs' => [
                "Quem trata os seus dados: {$who}" . ($address !== '' ? ", {$address}" : '') . '.',
                'Para quê: analisar o pedido de financiamento da viatura e apresentá-lo à instituição de crédito.',
                "Quem recebe os documentos: {$name} e a instituição de crédito a quem o pedido for apresentado.",
                "Quanto tempo ficam guardados: os documentos são apagados automaticamente {$days} dias depois do último envio, ou antes, se o pedir.",
                'Os seus direitos: pode pedir o acesso, a retificação ou o apagamento dos seus dados e opor-se ao tratamento'
                    . ($contact !== '' ? ", através de {$contact}" : '') . '. Pode também apresentar reclamação à Comissão Nacional de Proteção de Dados (CNPD).',
            ],
        ];
    }

    public static function privacyText(Company $company): string
    {
        $n = self::privacy($company);

        return $n['title'] . "\n\n" . implode("\n", $n['paragraphs']);
    }

    public static function ccCopy(Company $company): string
    {
        $name = (string) ($company->fiscal_name ?: $company->trade_name);

        return "Autorizo {$name} a guardar uma cópia do meu Cartão de Cidadão, só para a análise do pedido de financiamento, "
            . 'nos termos do artigo 5.º da Lei n.º 7/2007, de 5 de fevereiro.';
    }
}
