<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/**
 * O analisador antivírus dos ficheiros que chegam do público (documentos de financiamento).
 * scan() devolve "clean", "infected" ou lança uma exceção (o analisador não respondeu).
 */
interface VirusScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';

    public function configured(): bool;

    public function scan(string $disk, string $path): string;
}
