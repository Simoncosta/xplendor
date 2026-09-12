<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DMS — Caminho B: modelo de documento .docx (scoped por company).
 */
class DocumentTemplate extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = [
        'company_id',
        'name',
        'original_path',
        'archived',
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
