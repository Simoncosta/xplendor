<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use Carbon\CarbonImmutable;

/**
 * Transforma um snapshot de orçamento no $doc da vista pdf.quote: textos e valores
 * formatados em português de Portugal (datas por extenso, euros com vírgula).
 * Os dados legais vêm de config('legal') (a mesma fonte das páginas legais do site).
 */
class QuotePdfPresenter
{
    private const MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const UNIT_SUFFIX = ['month' => '/mês', 'hour' => '/hora', 'project' => ''];
    private const MINUS = '−';

    public function present(array $s): array
    {
        $legal = config('legal');
        $texts = config('quotes.texts');
        $res = resource_path('pdf');
        $isDraft = ($s['status'] ?? null) === 'draft';
        $validUntil = $s['valid_until'] ? self::longDate($s['valid_until']) : null;

        $sections = [];
        $totals = [];
        foreach (['monthly' => 'Serviços mensais', 'one_off' => 'Serviços de valor único'] as $bucket => $title) {
            $b = $s['buckets'][$bucket];
            if ($b['count'] === 0) {
                continue;
            }
            $monthly = $bucket === 'monthly';
            $suffix = $monthly ? '/mês' : '';
            $lines = array_values(array_filter($s['lines'], fn ($l) => ($l['billing_type'] === 'monthly') === $monthly));

            $package = null;
            if ($b['discount'] > 0) {
                $g = $s['global_discount'];
                $label = $g['label'] . ($g['type'] === 'percent' ? ' (' . self::number($g['value']) . '%)' : '');
                $package = ['label' => $label, 'value' => self::MINUS . self::money($b['discount']) . $suffix];
            }

            $sections[] = [
                'title'    => $title,
                'subtitle' => $monthly ? '(cobrados todos os meses)' : '(pagos uma só vez)',
                'lines'    => array_map(fn ($l) => [
                    'name'        => $l['name'],
                    'optional'    => (bool) ($l['is_optional'] ?? false),
                    'description' => $l['description'],
                    'quantity'    => self::number($l['quantity']) . ($l['unit'] === 'hour' ? ' h' : ''),
                    'unit_price'  => self::money($l['unit_price']) . (self::UNIT_SUFFIX[$l['unit']] ?? ''),
                    'discount'    => self::lineDiscount($l, $suffix),
                    'total'       => self::money($l['line_total']) . $suffix,
                ], $lines),
                'subtotal'         => self::money($b['subtotal']) . $suffix,
                'package_discount' => $package,
                'total_label'      => $monthly ? 'Total mensal' : 'Total valor único',
                'total'            => self::money($b['total']) . $suffix,
            ];
            $totals[] = ['label' => $monthly ? 'Total mensal' : 'Total valor único', 'value' => self::money($b['total']), 'unit' => $suffix];
        }

        $conditions = [
            ['key' => 'IVA', 'value' => $texts['vat_condition']],
            ['key' => 'Anúncios', 'value' => $texts['ads_condition']],
        ];
        // Condições dos serviços mensais só com linhas mensais; a do valor único só com linhas de valor único.
        $hasMonthly = $s['buckets']['monthly']['count'] > 0;
        $hasOneOff = $s['buckets']['one_off']['count'] > 0;
        $minimum = $hasMonthly && ! empty($s['minimum_contract_months']) ? self::months((int) $s['minimum_contract_months']) : null;
        if ($hasMonthly && self::filled($s['monthly_start_terms'] ?? null)) {
            $conditions[] = ['key' => 'Início dos serviços mensais', 'value' => trim($s['monthly_start_terms'])];
        }
        if ($minimum) {
            $conditions[] = ['key' => 'Contrato mínimo', 'value' => $minimum . ' para os serviços mensais.'];
        }
        if ($hasMonthly && self::filled($s['payment_terms_monthly'] ?? null)) {
            $conditions[] = ['key' => 'Pagamento dos serviços mensais', 'value' => trim($s['payment_terms_monthly'])];
        }
        if ($hasOneOff && self::filled($s['payment_terms_one_off'] ?? null)) {
            $conditions[] = ['key' => 'Pagamento do valor único', 'value' => trim($s['payment_terms_one_off'])];
        }
        $conditions[] = ['key' => 'Validade', 'value' => $validUntil
            ? "Este orçamento é válido até {$validUntil}."
            : 'Este orçamento é válido durante ' . config('quotes.validity_days') . ' dias a contar da data de envio.'];

        $customerLines = array_values(array_filter([
            $s['customer']['contact'] ?? null,
            $s['customer']['email'] ?? null,
            $s['customer']['phone'] ?? null,
            ! empty($s['customer']['nif']) ? 'NIF ' . $s['customer']['nif'] : null,
        ]));

        $number = $s['number'] ?? 'Rascunho';
        $versionLabel = 'Versão ' . $s['version'] . ($isDraft ? ' (rascunho, por enviar)' : '');

        return [
            'title'  => 'Orçamento ' . $number,
            'assets' => [
                'logo'          => $res . '/xplendor-x.png',
                'font_regular'  => $res . '/fonts/Inter-Regular.ttf',
                'font_semibold' => $res . '/fonts/Inter-SemiBold.ttf',
                'font_bold'     => $res . '/fonts/Inter-Bold.ttf',
            ],
            'legal' => [
                'brand'        => $legal['brand'],
                'brand_line'   => 'XPLENDOR é uma marca de ' . $legal['brandOwnerName'] . ' · NIF ' . $legal['nif'],
                'contact_line' => $legal['address'] . ' · ' . $legal['email'] . ' · ' . $legal['website'],
                'socials'      => $legal['socials'],
            ],
            'number'           => $number,
            'version_label'    => $versionLabel,
            'issued_at'        => $s['issued_at'] ? self::longDate($s['issued_at']) : self::longDate(CarbonImmutable::now('Europe/Lisbon')->toDateString()),
            'valid_until'      => $validUntil ?? config('quotes.validity_days') . ' dias após o envio',
            'minimum_contract' => $minimum,
            'customer'         => ['name' => $s['customer']['name'], 'lines' => $customerLines],
            // Título e introdução são opcionais: vazios, não aparecem no PDF.
            'title_text'       => self::filled($s['title'] ?? null) ? trim($s['title']) : null,
            'intro'            => self::filled($s['intro'] ?? null) ? trim($s['intro']) : null,
            'sections'         => $sections,
            'totals'           => $totals,
            'vat_note'         => $texts['vat_note'],
            'conditions'       => $conditions,
            'acceptance_note'  => 'Para aceitar este orçamento, use o link que recebeu com ele'
                . (collect($s['lines'])->contains(fn ($l) => ! empty($l['is_optional'])) ? ' (os serviços marcados como opcionais podem ficar de fora)' : '')
                . ', responda ao email em que o recebeu ou contacte-nos através de ' . $legal['email'] . '.',
        ];
    }

    public static function longDate(string $date): string
    {
        $d = CarbonImmutable::parse($date);

        return $d->day . ' de ' . self::MONTHS[$d->month] . ' de ' . $d->year;
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }

    private static function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    private static function filled(?string $text): bool
    {
        return $text !== null && trim($text) !== '';
    }

    private static function months(int $n): string
    {
        return $n === 1 ? '1 mês' : "{$n} meses";
    }

    private static function lineDiscount(array $l, string $suffix): string
    {
        if (($l['line_discount'] ?? 0) <= 0) {
            return '';
        }

        return $l['discount_type'] === 'percent'
            ? self::MINUS . self::number((float) $l['discount_value']) . '%'
            : self::MINUS . self::money((float) $l['line_discount']) . $suffix;
    }
}
