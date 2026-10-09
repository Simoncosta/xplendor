<?php

declare(strict_types=1);

namespace App\Services;

/**
 * XPLENDOR — QR code das faturas portuguesas (Portaria 195/2020, especificações da AT).
 * Texto "A:…*B:…*…". Parse COMPLETO, sem IA: é a fonte do cabeçalho da fatura.
 *
 *   A NIF emitente · B NIF adquirente · C país adquirente · D tipo · E estado · F data (AAAAMMDD)
 *   G nº documento · H ATCUD
 *   I1 espaço fiscal (PT, PT-AC, PT-MA ou 0) · I2 base isenta · I3/I4 base/IVA reduzida
 *   I5/I6 base/IVA intermédia · I7/I8 base/IVA normal · J1–J8 e K1–K8 = 2.º e 3.º espaço fiscal
 *   L não sujeito/não tributável · M imposto do selo · N total IVA · O total c/ impostos
 *   P retenção na fonte · Q 4 carateres do hash · R nº certificado · S outras informações
 *
 * As taxas de cada espaço fiscal vêm de config('services.openai.ocr.vat_rates') — as
 * linhas lidas pela IA são conferidas contra estas bases POR TAXA.
 */
class AtInvoiceQr
{
    private const AMOUNT_KEYS = ['I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'J2', 'J3', 'J4', 'J5', 'J6', 'J7', 'J8',
        'K2', 'K3', 'K4', 'K5', 'K6', 'K7', 'K8', 'L', 'M', 'N', 'O', 'P'];
    private const REQUIRED = ['A', 'B', 'D', 'F', 'G', 'H', 'O'];

    /**
     * Devolve a estrutura do QR, ou null se o texto não for um QR da AT. 'valid' = tem os
     * campos obrigatórios e valores coerentes; 'errors' explica o que falta.
     */
    public static function parse(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || ! preg_match('/^A:[^*]*\*B:/', $raw)) {
            return null;
        }

        $fields = [];
        foreach (explode('*', $raw) as $part) {
            $pos = strpos($part, ':');
            if ($pos === false) {
                continue;
            }
            $fields[strtoupper(trim(substr($part, 0, $pos)))] = trim(substr($part, $pos + 1));
        }

        $errors = [];
        foreach (self::REQUIRED as $k) {
            if (($fields[$k] ?? '') === '') {
                $errors[] = "campo {$k} em falta";
            }
        }
        $amount = function (string $k) use ($fields, &$errors): ?int {
            if (! isset($fields[$k]) || $fields[$k] === '') {
                return null;
            }
            if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $fields[$k])) {
                $errors[] = "valor inválido em {$k}";

                return null;
            }

            return (int) round(((float) $fields[$k]) * 100);
        };

        $date = null;
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $fields['F'] ?? '', $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (isset($fields['F'])) {
            $errors[] = 'data (F) inválida';
        }

        // Espaços fiscais I, J, K → bases/IVA por taxa.
        $rates = (array) config('services.openai.ocr.vat_rates', ['PT' => [6, 13, 23]]);
        $spaces = [];
        $byRate = [];
        foreach (['I', 'J', 'K'] as $p) {
            $space = strtoupper($fields["{$p}1"] ?? '');
            if ($space === '' || $space === '0') {
                continue;
            }
            $spaceRates = $rates[$space] ?? null;
            if ($spaceRates === null) {
                $errors[] = "espaço fiscal desconhecido ({$space})";
            }
            $slots = [];
            $exempt = $amount("{$p}2");
            if ($exempt !== null) {
                $slots[] = ['rate' => 0, 'base_cents' => $exempt, 'vat_cents' => 0];
            }
            foreach ([[3, 4, 0], [5, 6, 1], [7, 8, 2]] as [$b, $v, $idx]) {
                $base = $amount("{$p}{$b}");
                $vat = $amount("{$p}{$v}");
                if ($base === null && $vat === null) {
                    continue;
                }
                $slots[] = ['rate' => $spaceRates[$idx] ?? null, 'base_cents' => (int) $base, 'vat_cents' => (int) $vat];
            }
            $spaces[] = ['space' => $space, 'slots' => $slots];
            foreach ($slots as $s) {
                if ($s['rate'] === null) {
                    continue;
                }
                $byRate[$s['rate']] ??= ['rate' => $s['rate'], 'base_cents' => 0, 'vat_cents' => 0];
                $byRate[$s['rate']]['base_cents'] += $s['base_cents'];
                $byRate[$s['rate']]['vat_cents'] += $s['vat_cents'];
            }
        }
        ksort($byRate);

        $nif = fn (?string $v) => $v === null ? null : (preg_replace('/\s+/', '', $v) ?: null);
        $values = array_combine(self::AMOUNT_KEYS, array_map($amount, self::AMOUNT_KEYS));

        return [
            'raw'               => $raw,
            'fields'            => $fields,
            'valid'             => $errors === [],
            'errors'            => array_values(array_unique($errors)),
            'issuer_nif'        => $nif($fields['A'] ?? null),
            'buyer_nif'         => $nif($fields['B'] ?? null),
            'buyer_country'     => $fields['C'] ?? null,
            'doc_type'          => $fields['D'] ?? null,
            'doc_status'        => $fields['E'] ?? null,
            'issue_date'        => $date,
            'number'            => ($fields['G'] ?? '') !== '' ? $fields['G'] : null,
            'atcud'             => ($fields['H'] ?? '') !== '' ? $fields['H'] : null,
            'spaces'            => $spaces,
            'by_rate'           => array_values($byRate),
            'not_subject_cents' => $values['L'],
            'stamp_cents'       => $values['M'],
            'vat_total_cents'   => $values['N'],
            'total_cents'       => $values['O'],
            'withholding_cents' => $values['P'],
            'hash'              => $fields['Q'] ?? null,
            'certificate'       => $fields['R'] ?? null,
            'other'             => $fields['S'] ?? null,
        ];
    }

    /** Soma das bases (todas as taxas, incluindo isenta). */
    public static function taxableBaseCents(array $qr): int
    {
        return array_sum(array_column($qr['by_rate'] ?? [], 'base_cents'));
    }
}
