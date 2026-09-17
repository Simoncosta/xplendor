<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * XPLENDOR — shape enxuto de um veículo na consola de STOCK GLOBAL (/admin).
 * Allow-list para uma listagem transversal leve (não é a ficha completa).
 * `company_name` é sempre emitido — é uma vista de várias empresas.
 */
class AdminStockCarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Thumbnail = 1.ª imagem (a relação images já vem ordenada por is_primary).
        $thumb = $this->whenLoaded('images', fn () => optional($this->images->first())->image);

        return [
            'id'                => $this->id,
            'company_id'        => $this->company_id,
            'company_name'      => $this->whenLoaded('company', fn () => $this->company?->trade_name ?: $this->company?->fiscal_name),
            'brand'             => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'model'             => $this->whenLoaded('model', fn () => $this->model?->name),
            'version'           => $this->version,
            'vehicle_type'      => $this->vehicle_type,
            'status'            => $this->status,
            'registration_year' => $this->registration_year,
            'mileage_km'        => $this->mileage_km !== null ? (int) $this->mileage_km : null,
            'price_gross'       => $this->price_gross !== null ? (float) $this->price_gross : null,
            'promo_price_gross' => $this->promo_price_gross !== null ? (float) $this->promo_price_gross : null,
            'hide_price_online' => (bool) $this->hide_price_online,
            'thumbnail'         => $thumb,
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }
}
