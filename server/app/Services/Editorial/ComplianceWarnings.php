<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Bússola Master, R3: os avisos de conformidade da Linha Editorial (só avisos, nunca bloqueiam).
 * Nas publicações ligadas a um imóvel (pela Bússola do imobiliário), a classe energética é
 * obrigatória em qualquer anúncio de venda ou arrendamento (Decreto-Lei 101-D/2020, artigo 18.º).
 */
final class ComplianceWarnings
{
    public const LAW = 'Decreto-Lei 101-D/2020, artigo 18.º';

    /** @return array{warnings: list<string>} */
    public static function for(EditorialPost $post, ?EditorialPostVersion $current = null): array
    {
        $ids = DB::table('compass_post_subjects')->where('editorial_post_id', $post->id)->where('subject_type', 'property')->pluck('subject_id');
        if ($ids->isEmpty()) {
            return ['warnings' => []];
        }
        $caption = mb_strtolower((string) ($current?->caption ?? ''));
        $warnings = [];
        foreach (Property::withTrashed()->where('company_id', $post->company_id)->whereIn('id', $ids)->get() as $p) {
            $label = $p->reference;
            if ($p->energyClassMissing()) {
                $warnings[] = "Falta a classe energética do imóvel {$label}: é obrigatória em qualquer anúncio de venda ou arrendamento (" . self::LAW . ').';
            } elseif ($caption !== '' && ! self::mentions($caption, (string) $p->energy_class)) {
                $warnings[] = "A legenda não refere a classe energética ({$p->energy_class}) do imóvel {$label}: é obrigatória em qualquer anúncio (" . self::LAW . ').';
            }
        }

        return ['warnings' => $warnings];
    }

    /** "classe energética" seguida da classe (B, B-, A+, ...), sem distinguir maiúsculas; "isento" também vale. */
    private static function mentions(string $caption, string $class): bool
    {
        $c = preg_quote(mb_strtolower($class), '/');

        return (bool) preg_match('/classe\s+energ[ée]tica\s*:?\s*' . $c . '(?![a-z0-9+\-])/u', $caption);
    }
}
