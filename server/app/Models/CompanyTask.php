<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * XPLENDOR — Tarefa interna do cliente (Kanban do stand). Scoped por company_id
 * e PARTILHADA por toda a equipa da empresa. Colunas fixas: todo | doing | done.
 */
class CompanyTask extends Model implements AuditableContract
{
    use Auditable;

    // Colunas fixas do quadro (A Fazer / Em Curso / Concluído).
    public const STATUSES = ['todo', 'doing', 'done'];

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'status',
        'order',
        'assignee_user_id',
        'created_by',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    protected $attributes = [
        'status' => 'todo',
        'order' => 0,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Responsável pela tarefa (opcional, utilizador da mesma empresa). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** Quem criou a tarefa. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
