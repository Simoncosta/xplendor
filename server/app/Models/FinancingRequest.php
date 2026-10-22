<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um pedido de documentos: de financiamento (com 1 ou 2 titulares, cada um com o seu link) ou da
 * retoma de uma lead (um só link, com as observações). Guarda a lista pedida, o ano do IRS, as
 * pastas dos Ficheiros e a retenção, que conta do último envio de qualquer titular.
 */
class FinancingRequest extends Model
{
    public const FINANCING = 'financiamento';
    public const TRADE_IN = 'retoma';

    protected $fillable = [
        'company_id', 'car_id', 'car_lead_id', 'created_by_user_id', 'purpose', 'items', 'other_description', 'irs_year', 'notes',
        'folder_id', 'photos_folder_id', 'last_upload_at', 'last_notified_at', 'purge_warned_at', 'purged_at',
    ];

    protected $casts = [
        'items' => 'array', 'irs_year' => 'integer', 'last_upload_at' => 'datetime', 'last_notified_at' => 'datetime',
        'purge_warned_at' => 'datetime', 'purged_at' => 'datetime',
    ];

    public function isTradeIn(): bool
    {
        return $this->purpose === self::TRADE_IN;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CarLead::class, 'car_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(FinancingLink::class)->orderBy('holder')->orderBy('id');
    }
}
