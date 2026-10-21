<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/** Sem analisador configurado (ANTIVIRUS_DRIVER=none): os documentos ficam "não analisados". */
final class NullScanner implements VirusScanner
{
    public function configured(): bool
    {
        return false;
    }

    public function ping(): bool
    {
        return false;
    }

    public function scan(string $disk, string $path): string
    {
        throw new \LogicException('Sem analisador antivírus configurado.');
    }

    public function lastSignature(): ?string
    {
        return null;
    }
}
