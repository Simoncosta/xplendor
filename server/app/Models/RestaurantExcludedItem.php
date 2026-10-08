<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um artigo excluído das sugestões (permanente até "Voltar a incluir"), com quem e quando. */
class RestaurantExcludedItem extends Model
{
    protected $fillable = ['company_id', 'product_pingwin_id', 'product_name', 'excluded_by_user_id', 'excluded_at', 'included_by_user_id', 'included_at'];

    protected $casts = ['excluded_at' => 'datetime', 'included_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('included_at');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by_user_id');
    }

    /** @return array<string, true> os artigos excluídos agora (id do PingWin => true) */
    public static function activeIds(int $companyId): array
    {
        return self::where('company_id', $companyId)->active()->pluck('product_pingwin_id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }
}
