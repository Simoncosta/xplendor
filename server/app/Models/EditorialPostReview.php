<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Decisão do cliente sobre uma versão: aprovar ou pedir alterações. Uma aprovação por versão. */
class EditorialPostReview extends Model
{
    public const APPROVED = 'approved';
    public const CHANGES_REQUESTED = 'changes_requested';

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'editorial_post_id', 'version_id', 'decision', 'via', 'review_link_id', 'user_id', 'reviewer_name', 'message', 'device', 'approved_version_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
