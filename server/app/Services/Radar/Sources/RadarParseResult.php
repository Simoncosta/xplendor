<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

/** Radar: o que saiu da leitura de uma resposta: os itens e os que ficaram fora do âmbito (BCE). */
final class RadarParseResult
{
    /** @param RadarItemDto[] $items */
    public function __construct(public readonly array $items, public readonly int $outOfScope = 0) {}
}
