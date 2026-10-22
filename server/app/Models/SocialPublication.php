<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A publicação automática de uma rede (F2): uma por linha de editorial_post_networks. Nasce
 * "agendado" quando a publicação é programada (F2.2); o publicador passa-a a "a publicar" e depois
 * a "publicado" (com o media e o permalink) ou "falhou" (com o código e o motivo). "Cancelado"
 * quando a publicação volta atrás, muda de data ou a rede é dispensada antes de sair.
 */
class SocialPublication extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_PUBLISHING, self::STATUS_PUBLISHED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** Nomes no ecrã. */
    public const LABELS = [
        self::STATUS_SCHEDULED => 'Agendado',
        self::STATUS_PUBLISHING => 'A publicar',
        self::STATUS_PUBLISHED => 'Publicado',
        self::STATUS_FAILED => 'Falhou',
        self::STATUS_CANCELLED => 'Cancelado',
    ];

    protected $fillable = [
        'company_id', 'editorial_post_id', 'editorial_post_network_id', 'network', 'status', 'scheduled_for',
        'attempts', 'next_attempt_at', 'container_id', 'media_id', 'permalink', 'error_code', 'error_message',
        'published_at', 'cancelled_at', 'alerted_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'next_attempt_at' => 'datetime',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'alerted_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /** Ainda pode sair (agendada): é a única que se reprograma ou cancela sem chamar a Meta. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(EditorialPost::class, 'editorial_post_id');
    }

    public function postNetwork(): BelongsTo
    {
        return $this->belongsTo(EditorialPostNetwork::class, 'editorial_post_network_id');
    }

    /** Para o ecrã: estado, nome, hora marcada, link e motivo do erro. */
    public function present(): array
    {
        return [
            'status' => $this->status,
            'label' => self::LABELS[$this->status] ?? $this->status,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'permalink' => $this->permalink,
            'error_message' => $this->error_message,
            'error_code' => $this->error_code,
            'attempts' => $this->attempts,
            // F2.7: a próxima tentativa (ou verificação) marcada, quando a publicação espera.
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
        ];
    }
}
