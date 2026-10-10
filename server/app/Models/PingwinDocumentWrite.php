<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * XPLENDOR — FB-1: uma escrita de documento no PingWin (lançar em rascunho / fechar / anular),
 * feita por um job no worker e confirmada por releitura.
 */
class PingwinDocumentWrite extends Model
{
    public const LAUNCH = 'launch';
    public const CLOSE = 'close';
    public const VOID = 'void';

    public const PENDING = 'pendente';
    public const OK = 'ok';
    public const ERROR = 'erro';                       // nada foi gravado: pode tentar-se de novo
    public const CONFIRM_ERROR = 'erro_confirmacao';   // o SAVE pode ter corrido: rever, nunca repetir sozinho

    protected $fillable = [
        'company_id', 'ocr_invoice_id', 'user_id', 'action', 'status', 'docheader_id', 'document',
        'payload', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'result'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OcrInvoice::class, 'ocr_invoice_id');
    }
}
