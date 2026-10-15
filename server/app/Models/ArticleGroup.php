<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — um grupo de prováveis duplicados (pelo nome normalizado) e o artigo principal
 * escolhido. Só na XPLENDOR: não escreve no PingWin. O seletor de componentes das fichas mostra o
 * principal com uma estrela.
 */
class ArticleGroup extends Model
{
    protected $fillable = ['company_id', 'key', 'label', 'main_product_pingwin_id', 'chosen_by', 'chosen_at'];

    protected $casts = ['chosen_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(ArticleGroupMember::class, 'group_id');
    }

    public function chooser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chosen_by');
    }
}
