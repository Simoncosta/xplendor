<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — Config de documento PingWin (Definições→Documentos).
 * UNIQUE (company_id, external_id) → UPSERT idempotente no sync.
 *
 * Fase D0 (leitura rica): além do básico (lista), guarda a config COMPLETA — os _id-chave
 * em colunas + o resto do maindataset em `raw`, as 14 tabelas filhas em JSON (uma coluna
 * cada), os additionalfields e um snapshot das options dos selects.
 *
 * ⚠️ As colunas "pesadas" (JSON ricas) estão em $hidden para a LISTA ficar leve; o endpoint
 * de DETALHE torna-as visíveis (makeVisible) quando precisa da config completa.
 */
class PingwinDocumentConfig extends Model
{
    /** Colunas JSON ricas (filhas + raw + options + additionalfields). */
    public const RICH_JSON_COLUMNS = [
        'raw', 'options', 'additionalfields_maindataset', 'additionalfields_storedataset',
        'entitytype_docconfig', 'docconfig_detailstatus', 'default_detailstatus',
        'docconfig_docmovreason', 'docconfig_docstatus', 'default_docsatatus',
        'docconfig_docaccount', 'docconfig_local', 'docconfig_import',
        'docconfig_paymethod', 'docconfig_docreference', 'docconfig_paycond',
        'userrole_docconfig', 'store_docconfig',
    ];

    protected $fillable = [
        'company_id', 'external_id', 'code', 'description',
        'entitytype', 'fiscaltype', 'fiscaltype_description', 'deleted', 'synced_at',
        // D0 — _id-chave (híbrido) + marca de sync rica
        'taxscenario_id', 'doctype_id', 'docfiscaltype_id', 'default_paycond_id', 'stock_signal', 'docseries_id',
        // S1 conta corrente — flag "Pago" (maindataset.settled): documento auto-pago
        'settled',
        'rich_synced_at',
        // D0 — colunas JSON ricas
        'raw', 'options', 'additionalfields_maindataset', 'additionalfields_storedataset',
        'entitytype_docconfig', 'docconfig_detailstatus', 'default_detailstatus',
        'docconfig_docmovreason', 'docconfig_docstatus', 'default_docsatatus',
        'docconfig_docaccount', 'docconfig_local', 'docconfig_import',
        'docconfig_paymethod', 'docconfig_docreference', 'docconfig_paycond',
        'userrole_docconfig', 'store_docconfig',
    ];

    protected $casts = [
        'deleted'                       => 'boolean',
        'settled'                       => 'boolean',
        'synced_at'                     => 'datetime',
        'rich_synced_at'                => 'datetime',
        'raw'                           => 'array',
        'options'                       => 'array',
        'additionalfields_maindataset'  => 'array',
        'additionalfields_storedataset' => 'array',
        'entitytype_docconfig'          => 'array',
        'docconfig_detailstatus'        => 'array',
        'default_detailstatus'          => 'array',
        'docconfig_docmovreason'        => 'array',
        'docconfig_docstatus'           => 'array',
        'default_docsatatus'            => 'array',
        'docconfig_docaccount'          => 'array',
        'docconfig_local'               => 'array',
        'docconfig_import'              => 'array',
        'docconfig_paymethod'           => 'array',
        'docconfig_docreference'        => 'array',
        'docconfig_paycond'             => 'array',
        'userrole_docconfig'            => 'array',
        'store_docconfig'               => 'array',
    ];

    /** Lista leve: esconde as colunas JSON pesadas (o detalhe faz makeVisible). */
    protected $hidden = self::RICH_JSON_COLUMNS;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
