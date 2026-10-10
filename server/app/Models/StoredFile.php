<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ficheiros: um ficheiro do catálogo. Enviado nos Ficheiros (source "upload") ou registado a
 * partir de um módulo (is_system, com source e source_ref): fica onde está (disk + path).
 * As imagens e os vídeos têm a media da Linha Editorial (media_asset_id) para as variantes.
 */
class StoredFile extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    protected $fillable = [
        'company_id', 'folder_id', 'name', 'extension', 'mime', 'size_bytes', 'disk', 'path', 'source', 'source_ref', 'source_id',
        'media_asset_id', 'is_system', 'uploaded_by_user_id', 'missing_at', 'deleted_at', 'deleted_by_user_id', 'purge_after',
    ];

    protected $casts = ['is_system' => 'boolean', 'missing_at' => 'datetime', 'deleted_at' => 'datetime', 'purge_after' => 'date', 'size_bytes' => 'integer'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function shortcuts(): HasMany
    {
        return $this->hasMany(StoredFileShortcut::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('deleted_at')->whereNull('missing_at');
    }

    public function kind(): string
    {
        $mime = (string) $this->mime;

        return match (true) {
            str_starts_with($mime, 'image/') => 'imagem',
            str_starts_with($mime, 'video/') => 'video',
            $mime === 'application/pdf' => 'pdf',
            default => 'documento',
        };
    }
}
