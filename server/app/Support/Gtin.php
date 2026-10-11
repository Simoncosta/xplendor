<?php

declare(strict_types=1);

namespace App\Support;

/**
 * XPLENDOR — F2d: códigos de barras GS1 (EAN-8, UPC-A, EAN-13, GTIN-14) nos códigos do fornecedor.
 *
 *  · inspect(): diz se um código TEM FORMATO de EAN/GTIN (só dígitos, com 8, 12, 13 ou 14) e se o
 *    dígito de controlo está certo. Os códigos internos do fornecedor (ex.: "29251", "A-12") não
 *    têm formato GS1 e não se validam (null).
 *  · related(): os códigos do MESMO produto noutra embalagem — um GTIN-14 (caixa) tem no meio os
 *    12 primeiros dígitos do EAN-13 da unidade (indicador 1–8 à frente, controlo novo no fim).
 *
 * Dígito de controlo GS1: da direita para a esquerda (sem o controlo), pesos 3,1,3,1…; o controlo
 * é o que falta para a soma chegar à dezena seguinte.
 */
final class Gtin
{
    public const KINDS = [8 => 'EAN-8', 12 => 'UPC-A', 13 => 'EAN-13', 14 => 'GTIN-14'];

    /** Só os dígitos, sem espaços (null se tiver outra coisa). */
    public static function digits(?string $code): ?string
    {
        $c = preg_replace('/\s+/', '', trim((string) $code)) ?? '';

        return $c !== '' && ctype_digit($c) ? $c : null;
    }

    /** O dígito de controlo para os dígitos SEM o controlo. */
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int) $body[$i]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }

    /** @return array{kind: string, valid: bool}|null null = não tem formato GS1 (não se valida) */
    public static function inspect(?string $code): ?array
    {
        $d = self::digits($code);
        if ($d === null || ! isset(self::KINDS[strlen($d)])) {
            return null;
        }

        return ['kind' => self::KINDS[strlen($d)], 'valid' => self::checkDigit(substr($d, 0, -1)) === (int) substr($d, -1)];
    }

    /** Tem formato GS1 e o controlo está errado → "código provavelmente mal lido". */
    public static function probablyMisread(?string $code): bool
    {
        $i = self::inspect($code);

        return $i !== null && ! $i['valid'];
    }

    /**
     * Códigos do mesmo produto noutra embalagem (só para códigos válidos):
     *  · GTIN-14 → o EAN-13 da unidade ("unidade");
     *  · EAN-13 → os GTIN-14 das caixas, com indicador 1–8 ("caixa").
     * @return list<array{code: string, relation: string}>
     */
    public static function related(?string $code): array
    {
        $d = self::digits($code);
        $i = self::inspect($d);
        if (! $i || ! $i['valid']) {
            return [];
        }
        if ($i['kind'] === 'GTIN-14') {
            $body = substr($d, 1, 12);

            return [['code' => $body . self::checkDigit($body), 'relation' => 'unidade']];
        }
        if ($i['kind'] === 'EAN-13') {
            $body = substr($d, 0, 12);
            $out = [];
            for ($ind = 1; $ind <= 8; $ind++) {
                $out[] = ['code' => $ind . $body . self::checkDigit($ind . $body), 'relation' => 'caixa'];
            }

            return $out;
        }

        return [];
    }
}
