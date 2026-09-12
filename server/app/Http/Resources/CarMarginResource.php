<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS Fase 2A — margem por viatura (ficha). Wrapper do MarginService::forCar
 * + `uses_vat` (o FE decide o rótulo: lucro vs margem bruta s/ IVA).
 */
class CarMarginResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var array $d */
        $d = $this->resource;

        return [
            'calculable'     => (bool) ($d['calculable'] ?? false),
            'reason'         => $d['reason'] ?? null,
            'sale_price'     => isset($d['sale_price']) ? (float) $d['sale_price'] : null,
            'purchase_price' => isset($d['purchase_price']) ? (float) $d['purchase_price'] : null,
            'expenses_total' => (float) ($d['expenses_total'] ?? 0),
            'expenses_count' => (int) ($d['expenses_count'] ?? 0),
            'margin'         => isset($d['margin']) && $d['margin'] !== null ? (float) $d['margin'] : null,
            'uses_vat'       => (bool) ($d['uses_vat'] ?? false),
        ];
    }
}
