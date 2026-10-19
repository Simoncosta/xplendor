<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Finanças › Mês: a classificação manual de um gasto "Por classificar", só na XPLENDOR
 * (nada se escreve no PingWin). Por documento (key "doc:<docheader_id>") ou para sempre para um
 * fornecedor (key "sup:<entity_pingwin_id>"). Só preenche o que não tem família: nunca muda uma
 * linha que já tem família no PingWin.
 */
class FinanceClassification extends Model
{
    public const DOCUMENT = 'documento';
    public const SUPPLIER = 'fornecedor';

    protected $fillable = ['company_id', 'scope', 'key', 'docheader_id', 'entity_pingwin_id', 'entity_name', 'group', 'created_by'];
}
