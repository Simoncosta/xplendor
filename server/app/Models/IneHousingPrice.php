<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola Master: o valor mediano das vendas por m² do INE (indicador 0012234), por freguesia ou concelho. */
class IneHousingPrice extends Model
{
    public const INDICATOR = '0012234';
    public const ATTRIBUTION = 'Fonte: Instituto Nacional de Estatística, IP – Portugal, Estatísticas de preços da habitação ao nível local, valor mediano das vendas de alojamentos familiares nos últimos 12 meses (euros por m²)';

    protected $fillable = ['geo_level', 'geo_code', 'geo_name', 'official_code', 'period_code', 'period_label', 'median_m2', 'has_value', 'ine_updated_on', 'fetched_at'];

    protected $casts = ['median_m2' => 'integer', 'has_value' => 'boolean', 'ine_updated_on' => 'date', 'fetched_at' => 'datetime'];

    /** O valor mais recente para um código oficial (DICOFRE ou DICO). */
    public static function latestFor(?string $officialCode): ?self
    {
        return $officialCode ? self::where('official_code', $officialCode)->orderByDesc('period_code')->first() : null;
    }

    /** A frase de atribuição do INE, tal e qual, com o período. */
    public function attribution(): string
    {
        return self::ATTRIBUTION . ", {$this->period_label}.";
    }
}
