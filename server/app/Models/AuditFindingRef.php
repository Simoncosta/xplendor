<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** XPLENDOR — Auditoria: uma entidade a que um achado se refere (para a coluna "Auditoria" das listas). */
class AuditFindingRef extends Model
{
    public $timestamps = false;

    protected $fillable = ['finding_id', 'company_id', 'entity_type', 'entity_id'];
}
