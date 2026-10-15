<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\Carbon;

/** XPLENDOR — Auditoria: números e datas nos textos dos achados (pt-PT). */
final class Fmt
{
    public static function eur(float|int|null $value, int $decimals = 2): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals, ',', ' ') . ' €';
    }

    /** Um preço por unidade: 2 casas; 4 só abaixo de 0,10 €. */
    public static function unitPrice(float|int|null $value): string
    {
        return $value === null ? '—' : self::eur($value, abs((float) $value) < 0.10 && (float) $value != 0.0 ? 4 : 2);
    }

    /** "24 vezes mais", "175 vezes menos" (a razão face ao preço habitual). */
    public static function times(float $ratio): string
    {
        $n = $ratio >= 1 ? $ratio : 1 / $ratio;
        $s = $n >= 10 ? (string) (int) round($n) : self::qty($n, 1);

        return $s . ' vezes ' . ($ratio >= 1 ? 'mais' : 'menos');
    }

    /** O documento sem o tipo à frente: "VFT BOVFT/1044" → "BOVFT/1044". */
    public static function doc(?string $document): string
    {
        $d = trim((string) $document);

        return preg_match('~^\S+\s+(\S+/\d+)$~', $d, $m) ? $m[1] : ($d !== '' ? $d : 'sem número');
    }

    /** O título: "entidade: o que está mal", até cerca de 70 caracteres (a entidade encurta-se). */
    public static function title(string $entity, string $problem, int $max = 70): string
    {
        $entity = trim(preg_replace('/\s+/', ' ', $entity));
        $room = $max - mb_strlen($problem) - 2;
        if (mb_strlen($entity) > $room) {
            $entity = rtrim(mb_substr($entity, 0, max(12, $room - 1))) . '…';
        }

        return "{$entity}: {$problem}";
    }

    public static function cents(?int $cents): string
    {
        return self::eur($cents === null ? null : $cents / 100);
    }

    public static function qty(float|int|null $value, int $decimals = 3): string
    {
        if ($value === null) {
            return '—';
        }
        $s = number_format((float) $value, $decimals, ',', ' ');

        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    /** A quantidade com a unidade, em minúsculas e com o plural: "24 unidades", "1 quilograma", "2,5 litros". */
    public static function units(float|int|null $value, string $unit, int $decimals = 3): string
    {
        $u = mb_strtolower(rtrim(trim($unit), '.')) ?: 'unidade';
        $u = ['kg' => 'quilograma', 'lt' => 'litro', 'l' => 'litro', 'un' => 'unidade', 'uni' => 'unidade', 'und' => 'unidade', 'gr' => 'grama', 'g' => 'grama', 'ml' => 'mililitro'][$u] ?? $u;
        $plural = ['unidade' => 'unidades', 'quilograma' => 'quilogramas', 'litro' => 'litros', 'metro' => 'metros', 'metro quadrado' => 'metros quadrados',
            'grama' => 'gramas', 'mililitro' => 'mililitros', 'dose' => 'doses', 'porção' => 'porções'];

        return self::qty($value, $decimals) . ' ' . ((float) $value === 1.0 ? $u : ($plural[$u] ?? $u));
    }

    /** Só a primeira letra em minúscula: "Cliques no WhatsApp" → "cliques no WhatsApp". */
    public static function lower(string $label): string
    {
        return mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    public static function pct(float|int|null $value, int $decimals = 0): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals, ',', ' ') . '%';
    }

    /** "quantas unidades", "quantos quilogramas", "quantos litros" (a unidade base, no plural, com a concordância). */
    public static function howMany(string $base): string
    {
        $b = mb_strtolower(trim($base));

        return match ($b) {
            'unidade' => 'quantas unidades',
            'quilograma' => 'quantos quilogramas',
            'litro' => 'quantos litros',
            'metro' => 'quantos metros',
            'metro quadrado' => 'quantos metros quadrados',
            default => "quantas unidades base ({$b})",
        };
    }

    public static function date(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '—';
    }
}
