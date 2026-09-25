<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Linha Editorial (B3a): registo de âncora HERDADA escondida por ocorrência
 * (company_id + anchor_id de content_anchors + occurrence_year). Ver migration.
 */
class EditorialHiddenAnchor extends Model
{
    protected $fillable = ['company_id', 'anchor_id', 'occurrence_year'];

    protected $casts = [
        'occurrence_year' => 'integer',
    ];
}
