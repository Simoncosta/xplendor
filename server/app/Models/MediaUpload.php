<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Envio de um ficheiro em partes de 8 MB (retomável a partir de received_bytes). */
class MediaUpload extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'user_id', 'impersonator_user_id', 'kind', 'original_name', 'extension', 'mime',
        'size_bytes', 'received_bytes', 'media_asset_id', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'received_bytes' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Ficheiro temporário com as partes já recebidas. */
    public function tempPath(): string
    {
        return "tmp/{$this->id}.part";
    }
}
