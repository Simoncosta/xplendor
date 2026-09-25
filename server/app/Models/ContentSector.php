<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * XPLENDOR — Linha Editorial: nó da ÁRVORE DE SETORES (auto-referencial).
 * type: universal | agrupador | folha. Só folhas são is_selectable.
 */
class ContentSector extends Model
{
    protected $fillable = ['name', 'slug', 'parent_id', 'type', 'is_selectable', 'sort'];

    protected $casts = [
        'is_selectable' => 'boolean',
        'sort'          => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function anchors(): HasMany
    {
        return $this->hasMany(ContentAnchor::class, 'sector_id');
    }

    /** Caminho da RAIZ até este nó (inclusive) — a base da herança de âncoras. */
    public function pathFromRoot(): array
    {
        $path = [];
        $node = $this;
        while ($node) {
            array_unshift($path, $node);
            $node = $node->parent; // sobe até à raiz (parent_id null)
        }

        return $path;
    }
}
