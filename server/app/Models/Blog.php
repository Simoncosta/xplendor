<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

/**
 * Artigo do blog de uma empresa. Estados: rascunho → em revisão → aprovado (agendado para
 * published_at) → publicado. O slug é único por empresa e fica fixo a partir da primeira
 * publicação (first_published_at). A lógica vive no BlogService e no BlogWorkflowService.
 */
class Blog extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    public const DRAFT = 'draft';
    public const IN_REVIEW = 'in_review';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';

    public const STATUSES = [self::DRAFT, self::IN_REVIEW, self::APPROVED, self::PUBLISHED];

    protected $fillable = [
        'title',
        'subtitle',
        'slug',
        'banner',
        'excerpt',
        'content',
        'tags',
        'category',
        'status',
        'published_at',
        'first_published_at',
        'read_time',
        'meta_title',
        'meta_description',
        'focus_keyword',
        'seo_answer_first_ok',
        'og_title',
        'og_description',
        'og_image',
        'user_id',
        'company_id',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'review_note',
    ];

    protected $casts = [
        'tags' => 'array',
        'published_at' => 'datetime',
        'first_published_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'seo_answer_first_ok' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** O slug (endereço) fica fixo depois da primeira publicação. */
    public function isSlugLocked(): bool
    {
        return $this->first_published_at !== null || $this->status === self::PUBLISHED;
    }
}
