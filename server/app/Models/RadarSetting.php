<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Radar: as definições de uma empresa (desporto, clubes, temas silenciados). Sem linha, valem as omissões. */
class RadarSetting extends Model
{
    protected $fillable = ['company_id', 'sports_enabled', 'sports_clubs', 'muted', 'updated_by_user_id'];

    protected $casts = ['sports_enabled' => 'boolean', 'sports_clubs' => 'array', 'muted' => 'array'];

    /** @return array{sports_enabled: bool, sports_clubs: list<string>, muted: list<array<string, mixed>>} */
    public static function forCompany(int $companyId): array
    {
        $row = self::where('company_id', $companyId)->first();

        return [
            'sports_enabled' => (bool) ($row->sports_enabled ?? false),
            'sports_clubs' => array_values((array) ($row->sports_clubs ?? [])),
            'muted' => array_values((array) ($row->muted ?? [])),
        ];
    }
}
