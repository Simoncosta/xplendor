<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Comentário numa publicação: interno (só a equipa XPLENDOR) ou partilhado com o cliente. */
class EditorialPostComment extends Model
{
    public const INTERNAL = 'internal';
    public const SHARED = 'shared';

    protected $fillable = ['company_id', 'editorial_post_id', 'version_id', 'user_id', 'impersonator_user_id', 'author_name', 'body', 'visibility'];
}
