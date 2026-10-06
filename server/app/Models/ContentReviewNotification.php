<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de um link de aprovação para quem produz (aberturas, decisões, comentários,
 * lembretes). Também vai para o sino; aqui fica à espera do resumo por email (15 minutos).
 * company_id é a empresa a que o aviso se refere (a do link).
 */
class ContentReviewNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'content_review_link_id', 'severity', 'title', 'message', 'created_at', 'emailed_at'];

    protected $casts = ['created_at' => 'datetime', 'emailed_at' => 'datetime'];
}
