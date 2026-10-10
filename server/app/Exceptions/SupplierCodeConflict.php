<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * XPLENDOR — F2b: o artigo já tem OUTRO código para este fornecedor no PingWin. A UI pergunta
 * antes de substituir (replace_code).
 */
class SupplierCodeConflict extends \RuntimeException
{
    public function __construct(public readonly string $existingCode, public readonly string $newCode)
    {
        parent::__construct("O artigo já tem o código «{$existingCode}» para este fornecedor (a fatura diz «{$newCode}»).");
    }
}
