<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditRun;
use RuntimeException;

/** Já há uma corrida desta empresa e deste âmbito na fila ou a correr. */
final class AuditRunInProgress extends RuntimeException
{
    public function __construct(public readonly AuditRun $run)
    {
        parent::__construct('Já há uma auditoria destas a correr: aguarde que termine.');
    }
}
