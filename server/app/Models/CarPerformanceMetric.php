<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarPerformanceMetric extends Model
{
    protected $table = 'car_performance_metrics';

    protected $fillable = [
        // Identificação
        'car_id',
        'company_id',
        'channel',
        'period_start',
        'period_end',

        // Tráfego (input manual ou integração)
        'impressions',
        'clicks',
        'sessions',

        // Custo (input manual ou integração)
        'spend_amount',
        'cost_per_sale',

        // Conversão (alguns automáticos)
        'leads_count',
        'time_to_first_lead_hours',
        'time_to_sale_days',

        // Financeiro (input manual)
        'sale_price',
        'purchase_price',

        // Controlo
        'data_source',
        'requires_review',
        'notes',
    ];

    // Campos calculados — nunca aceites via fillable
    protected $guarded = [
        'ctr',
        'cpc',
        'cost_per_lead',
        'conversion_rate',
        'gross_margin',
        'roi',
    ];

    protected $casts = [
        'period_start'              => 'date',
        'period_end'                => 'date',
        'impressions'               => 'integer',
        'clicks'                    => 'integer',
        'sessions'                  => 'integer',
        'leads_count'               => 'integer',
        'time_to_first_lead_hours'  => 'integer',
        'time_to_sale_days'         => 'integer',
        'spend_amount'              => 'decimal:2',
        'ctr'                       => 'decimal:2',
        'cpc'                       => 'decimal:4',
        'cost_per_lead'             => 'decimal:2',
        'cost_per_sale'             => 'decimal:2',
        'conversion_rate'           => 'decimal:2',
        'sale_price'                => 'decimal:2',
        'purchase_price'            => 'decimal:2',
        'gross_margin'              => 'decimal:2',
        'roi'                       => 'decimal:2',
        'requires_review'           => 'boolean',
    ];

    // ── Constantes ────────────────────────────────────────────────────────────

    const CHANNELS = [
        'paid',
        'organic_search',
        'organic_social',
        'direct',
        'referral',
        'email',
        'utm',
    ];

    const DATA_SOURCES = [
        'manual',
        'google_ads',
        'meta_ads',
        'calculated',
    ];

    // ── Relações ──────────────────────────────────────────────────────────────

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCar($query, int $carId)
    {
        return $query->where('car_id', $carId);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeInPeriod($query, string $from, string $to)
    {
        return $query
            ->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to);
    }

    public function scopeRequiresReview($query)
    {
        return $query->where('requires_review', true);
    }

    // ── Recalcular campos computados ──────────────────────────────────────────

    public function recalculate(): void
    {
        $this->ctr = $this->impressions > 0
            ? round(($this->clicks / $this->impressions) * 100, 2)
            : null;

        $this->cpc = $this->clicks > 0
            ? round($this->spend_amount / $this->clicks, 4)
            : null;

        $this->cost_per_lead = $this->leads_count > 0
            ? round($this->spend_amount / $this->leads_count, 2)
            : null;

        $this->conversion_rate = $this->sessions > 0
            ? round(($this->leads_count / $this->sessions) * 100, 2)
            : null;

        if ($this->sale_price && $this->purchase_price) {
            $this->gross_margin = round(
                $this->sale_price - $this->purchase_price - $this->spend_amount,
                2
            );

            $this->roi = $this->spend_amount > 0
                ? round(($this->gross_margin / $this->spend_amount) * 100, 2)
                : null;
        }

        $this->saveQuietly(); // evita loop de observers
    }
}
