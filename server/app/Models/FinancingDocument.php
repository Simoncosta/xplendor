<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um documento recebido num link de financiamento. O ficheiro está no disco privado e no
 * catálogo dos Ficheiros (stored_file_id, pasta do sistema "Documentos de financiamento").
 * scan_status: a análise antivírus (pendente, limpo, infetado; "nao_analisado" sem analisador).
 */
class FinancingDocument extends Model
{
    public const SCAN_PENDING = 'pendente';
    public const SCAN_CLEAN = 'limpo';
    public const SCAN_INFECTED = 'infetado';
    public const SCAN_NONE = 'nao_analisado';

    protected $fillable = ['company_id', 'financing_link_id', 'kind', 'stored_file_id', 'name', 'extension', 'mime', 'size_bytes', 'scan_status', 'scanned_at'];

    protected $casts = ['size_bytes' => 'integer', 'scanned_at' => 'datetime'];

    /** Descarrega-se só depois de limpo (ou sem analisador configurado). */
    public function downloadable(): bool
    {
        return in_array($this->scan_status, [self::SCAN_CLEAN, self::SCAN_NONE], true);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FinancingLink::class, 'financing_link_id');
    }

    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }
}
