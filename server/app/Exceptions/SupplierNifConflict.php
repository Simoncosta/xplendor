<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PingwinSupplier;

/** Já existe um fornecedor ATIVO com este NIF (no espelho). */
class SupplierNifConflict extends \RuntimeException
{
    public function __construct(public readonly PingwinSupplier $existing)
    {
        parent::__construct("Já existe o fornecedor {$existing->code} — {$existing->name} com o NIF {$existing->tax_number}.");
    }
}
