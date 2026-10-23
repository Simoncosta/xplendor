<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parceiro 4: um ativo da Meta da agência (Página, conta de Instagram ou conta de anúncios) ligado a uma
 * empresa gerida. Cada ativo só numa empresa. "sem_tarefa": a Página ficou registada, mas a Meta não deu o
 * token da Página (a pessoa que ligou a Meta da agência não tem uma tarefa nela, atribuída no portfólio).
 */
class AgencyAssetLink extends Model
{
    public const PAGE = 'page';
    public const INSTAGRAM = 'instagram';
    public const AD_ACCOUNT = 'ad_account';
    public const TYPES = [self::PAGE, self::INSTAGRAM, self::AD_ACCOUNT];

    public const LINKED = 'ligado';
    public const NO_TASK = 'sem_tarefa';

    /** A origem gravada nas tabelas de sempre quando a ligação vem da agência. */
    public const ORIGIN = 'agencia';

    protected $fillable = ['agency_company_id', 'company_id', 'asset_type', 'external_id', 'page_id', 'name', 'business_id', 'status', 'linked_by_user_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
