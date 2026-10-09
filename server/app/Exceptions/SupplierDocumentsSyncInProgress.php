<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PingwinDocumentSyncRun;

/** Já existe uma sync de documentos de fornecedor em curso para a empresa. */
class SupplierDocumentsSyncInProgress extends \RuntimeException
{
    public function __construct(public readonly PingwinDocumentSyncRun $run)
    {
        parent::__construct("Já há uma sincronização de documentos em curso (run {$run->id}).");
    }
}
