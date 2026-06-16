<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Constants\StockThresholds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Wrap do paginator de candidatas com `meta` extra:
 *   - thresholds por vehicle_type (UI usa para decidir "is_stale" client-side
 *     também, ex: badge dinâmico em filtro de range)
 *   - summary opcional (totais de stock, marcados) — passado pelo Controller
 *     via `additional()` para evitar 2ª query desnecessária se o cliente só
 *     quer página de dados.
 *
 * Usa explicitamente $this->collects para forçar PromotionCandidateResource
 * em cada linha — `::collection()` infere mas é frágil a renames.
 */
class PromotionCandidatesCollection extends ResourceCollection
{
    public $collects = PromotionCandidateResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'thresholds' => [
                    'stock_age_days' => StockThresholds::STOCK_AGE_THRESHOLD,
                    'default'        => StockThresholds::STOCK_AGE_DEFAULT,
                ],
            ],
        ];
    }
}
