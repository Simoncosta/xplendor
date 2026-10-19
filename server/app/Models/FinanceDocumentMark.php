<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Por documento de fornecedor: a "Situação" (a forma de pagamento da folha da
 * contabilidade), o envio à contabilidade (data e forma) e a nota. Só na XPLENDOR.
 */
class FinanceDocumentMark extends Model
{
    public const SEND_METHODS = ['email' => 'Email', 'entregue' => 'Entregue em mão', 'plataforma' => 'Plataforma do contabilista', 'outro' => 'Outro'];

    protected $fillable = ['company_id', 'docheader_id', 'situation', 'sent_on', 'send_method', 'note', 'source', 'sent_by', 'updated_by'];

    protected $casts = ['sent_on' => 'date'];
}
