<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Finanças: uma regra de classificação "quando → grupo" (só na XPLENDOR). Por entidade,
 * por artigo (código), por família (caminho, com as subfamílias) ou por tipo de documento; pode ficar
 * restrita a uma entidade. Tem prioridade sobre a família do PingWin. Cada mudança fica no histórico
 * (finance_rule_events).
 */
class FinanceRule extends Model
{
    public const TYPES = ['artigo', 'entidade', 'tipo_documento', 'familia'];

    protected $fillable = ['company_id', 'match_type', 'match_values', 'match_label', 'entity_pingwin_id', 'entity_name', 'group', 'active', 'priority', 'created_by', 'updated_by'];

    protected $casts = ['match_values' => 'array', 'active' => 'boolean', 'priority' => 'integer'];
}
