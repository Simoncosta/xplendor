<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bússola comum: "Não voltar a sugerir" (um veículo, no stand). included_at null = ainda excluído. */
class CompassExcludedSubject extends Model
{
    public $timestamps = false;

    protected $fillable = ['provider', 'company_id', 'subject_type', 'subject_id', 'excluded_by_user_id', 'excluded_at', 'included_at', 'included_by_user_id'];

    protected $casts = ['excluded_at' => 'datetime', 'included_at' => 'datetime', 'subject_id' => 'integer'];

    /** @return array<int, true> os identificadores ainda excluídos */
    public static function activeIds(string $provider, int $companyId, string $subjectType): array
    {
        return array_fill_keys(self::where('provider', $provider)->where('company_id', $companyId)->where('subject_type', $subjectType)
            ->whereNull('included_at')->pluck('subject_id')->map(fn ($id) => (int) $id)->all(), true);
    }
}
