<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ficheiros: uma pasta (só na base de dados). system_key preenchido = pasta do sistema (criada
 * automaticamente, com a permissão da sua área; não se muda o nome, não se move, não se apaga).
 * Apagada = na reciclagem (deleted_at), até purge_after.
 */
class FileFolder extends Model
{
    protected $fillable = ['company_id', 'parent_id', 'name', 'system_key', 'created_by_user_id', 'deleted_at', 'deleted_by_user_id', 'purge_after'];

    protected $casts = ['deleted_at' => 'datetime', 'purge_after' => 'date'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'folder_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('deleted_at');
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /** A pasta do sistema de topo (a primeira parte da chave: "editorial:2026-10" → "editorial"). */
    public function systemRoot(): ?string
    {
        return $this->system_key === null ? null : explode(':', $this->system_key, 2)[0];
    }
}
