<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * DMS — Relatório de Satisfação (pós-venda).
 *
 * Pendura numa venda; o cliente abre-o por link público (public_token) sem login.
 */
class SatisfactionReport extends Model
{
    protected $fillable = [
        'company_id',
        'car_sale_id',
        'car_id',
        'public_token',
        'status',
        'rating',
        'public_message',
        'internal_feedback',
        'opened_at',
        'submitted_at',
        'expires_at',
        'sent_at',
        'sent_channel',
    ];

    protected $casts = [
        'rating'       => 'integer',
        'opened_at'    => 'datetime',
        'submitted_at' => 'datetime',
        'expires_at'   => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public const DEFAULT_EXPIRY_DAYS = 90;

    /** Token público forte, não-adivinhável (48 chars aleatórios). */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('public_token', $token)->exists());

        return $token;
    }

    /**
     * Garante que existe UM relatório para esta venda — cria-o na 1.ª vez, com
     * token estável, e devolve o existente nas seguintes. Fonte única de verdade
     * usada tanto no fecho da venda (automático) como na criação preguiçosa de
     * vendas antigas. NUNCA muda o token de um relatório já existente.
     */
    public static function ensureForSale(CarSale $sale): self
    {
        return static::firstOrCreate(
            ['car_sale_id' => $sale->id],
            [
                'company_id'   => $sale->company_id,
                'car_id'       => $sale->car_id,
                'public_token' => static::generateToken(),
                'status'       => 'pending',
                'expires_at'   => now()->addDays(self::DEFAULT_EXPIRY_DAYS),
            ]
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function carSale(): BelongsTo
    {
        return $this->belongsTo(CarSale::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SatisfactionReportPhoto::class)->orderBy('order')->orderBy('id');
    }
}
