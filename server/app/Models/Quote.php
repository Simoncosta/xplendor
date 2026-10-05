<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * XPLENDOR — Orçamento de serviços (gestão comercial, só a equipa XPLENDOR).
 * Transversal: não é de nenhuma empresa da plataforma, mas pode estar ligado a uma
 * (company_id) para ela decidir no painel dela. O cliente vem do módulo Clientes
 * da empresa da equipa (customer_id), com uma cópia do nome e dos contactos.
 *
 * Estados: draft → sent → accepted | refused | expired. Ao enviar, a versão fica
 * congelada (quote_versions, com o PDF). Alterar um enviado, recusado ou expirado
 * cria a versão seguinte em rascunho; um aceite não se altera (duplica-se).
 * Totais MENSAL e VALOR ÚNICO separados, sempre sem IVA (QuoteCalculator).
 */
class Quote extends Model implements AuditableContract
{
    use Auditable;

    public const STATUSES = ['draft', 'sent', 'accepted', 'refused', 'expired'];
    public const DECIDED = ['accepted', 'refused'];
    /** Estados em que alterar cria uma nova versão (em vez de editar a atual). */
    public const REOPENABLE = ['sent', 'refused', 'expired'];
    public const VALIDITY_DAYS = 30;
    public const TIMEZONE = 'Europe/Lisbon';

    protected $fillable = [
        'number', 'number_year', 'number_seq', 'version',
        'company_id', 'customer_id',
        'client_name', 'client_contact', 'client_email', 'client_phone',
        'title', 'intro', 'description', 'amount',
        'global_discount_type', 'global_discount_value', 'global_discount_target', 'global_discount_label',
        'total_monthly', 'total_one_off',
        'minimum_contract_months', 'payment_terms', 'monthly_start_terms', 'payment_terms_monthly', 'payment_terms_one_off',
        'status', 'sent_at', 'valid_until', 'decided_at', 'expired_at', 'legacy_status',
        'notes', 'created_by_user_id',
    ];

    protected $casts = [
        'amount'                => 'decimal:2',
        'global_discount_value' => 'decimal:2',
        'total_monthly'         => 'decimal:2',
        'total_one_off'         => 'decimal:2',
        'sent_at'               => 'datetime',
        'valid_until'           => 'date',
        'decided_at'            => 'datetime',
        'expired_at'            => 'datetime',
    ];

    protected $attributes = [
        'status'  => 'draft',
        'version' => 1,
    ];

    /** Ligação opcional a uma empresa da plataforma (decide no painel dela). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderByDesc('version');
    }

    public function isLinkedToCompany(): bool
    {
        return $this->company_id !== null;
    }

    /** "ORC-2026-001" ou "Rascunho" (o número só existe a partir do primeiro envio). */
    public function displayNumber(): string
    {
        return $this->number ?? 'Rascunho';
    }
}
