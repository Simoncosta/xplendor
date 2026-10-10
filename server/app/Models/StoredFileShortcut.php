<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ficheiros: "Guardar numa pasta" (o mesmo ficheiro numa pasta das pessoas, sem cópia). */
class StoredFileShortcut extends Model
{
    protected $fillable = ['company_id', 'stored_file_id', 'folder_id', 'created_by_user_id', 'deleted_at', 'purge_after'];

    protected $casts = ['deleted_at' => 'datetime', 'purge_after' => 'date'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }
}
