<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — artigo de agrupamento (por empresa e por artigo do PingWin): ao lançar uma fatura, as linhas
 * deste artigo juntam-se numa só (quantidade 1, total somado). Não mexe no PingWin.
 */
class ArticleGrouping extends Model
{
    protected $fillable = ['company_id', 'product_pingwin_id', 'is_grouping', 'changed_by', 'changed_at'];

    protected $casts = ['is_grouping' => 'boolean', 'changed_at' => 'datetime'];

    /** Os ids no PingWin dos artigos de agrupamento da empresa (como chaves, para isset). @return array<string, true> */
    public static function pingwinIdsFor(int $companyId): array
    {
        return self::where('company_id', $companyId)->where('is_grouping', true)->pluck('product_pingwin_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }
}
