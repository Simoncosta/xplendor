<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico de uma publicação: criação, mudanças de etapa, versões, decisões e comentários.
 * Guarda a pessoa real: em impersonation, user_id é o utilizador do cliente e
 * impersonator_user_id a pessoa da equipa XPLENDOR que fez a ação.
 */
class EditorialPostEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'editorial_post_id', 'type', 'from_stage', 'to_stage', 'version_id', 'user_id', 'impersonator_user_id', 'message', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
