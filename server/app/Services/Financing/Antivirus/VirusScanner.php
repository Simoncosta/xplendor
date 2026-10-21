<?php

declare(strict_types=1);

namespace App\Services\Financing\Antivirus;

/**
 * O analisador antivírus dos ficheiros que chegam do público (documentos de financiamento).
 * scan() devolve "clean" ou "infected"; se o analisador estiver em baixo ou a arrancar, lança
 * ClamdUnavailable (a análise espera e tenta outra vez); outra resposta, RuntimeException.
 */
interface VirusScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';

    public function configured(): bool;

    /** O analisador responde agora? */
    public function ping(): bool;

    public function scan(string $disk, string $path): string;

    /** A assinatura encontrada na última análise infetada (ex.: "Win.Test.EICAR_HDB-1"). */
    public function lastSignature(): ?string;
}
