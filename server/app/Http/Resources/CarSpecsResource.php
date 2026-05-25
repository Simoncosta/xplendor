<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarAiAnalysis;
use App\Models\CarSalePotentialScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarSpecsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Car $this */

        // Latest by MAX(id) per CLAUDE.md §6 — no relation on Car model for these
        $latestScore = CarSalePotentialScore::where('car_id', $this->id)
            ->orderByDesc('id')
            ->first();

        $latestAnalysis = CarAiAnalysis::where('car_id', $this->id)
            ->orderByDesc('id')
            ->first();

        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),

            'brand'   => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null,
            'model'   => $this->model ? ['id' => $this->model->id, 'name' => $this->model->name] : null,
            'version' => $this->version,

            'specs' => [
                'fuel_type'          => $this->fuel_type,
                'transmission'       => $this->transmission,
                'power_hp'           => $this->power_hp,
                'engine_capacity_cc' => $this->engine_capacity_cc,
                'doors'              => $this->doors,
                'seats'              => $this->seats,
                'segment'            => $this->segment,
                'exterior_color'     => $this->exterior_color,
            ],

            'state' => [
                'condition'     => $this->condition,
                'origin'        => $this->origin,
                'mileage_km'    => $this->mileage_km,
                'has_spare_key' => (bool) $this->has_spare_key,
                'has_manuals'   => (bool) $this->has_manuals,
                'is_trade_in'   => (bool) $this->is_resume,
            ],

            'price' => [
                'gross'              => $this->price_gross ? (float) $this->price_gross : null,
                'promo_gross'        => $this->promo_price_gross ? (float) $this->promo_price_gross : null,
                'promo_discount_pct' => $this->promo_discount_pct ? (float) $this->promo_discount_pct : null,
            ],

            'registration' => [
                'year'  => $this->registration_year,
                'month' => $this->registration_month,
            ],

            // PII — internal auth + tenant-scoped endpoint only; never use this Resource in public endpoints
            'identification' => [
                'license_plate' => $this->license_plate,
                'vin'           => $this->vin,
            ],

            'description' => $this->description_website_pt,

            // Internal DB column 'image' exposed as 'url' for cleaner API semantics
            'images' => $this->images?->map(fn ($img) => [
                'id'            => $img->id,
                'url'           => $img->image,
                'is_primary'    => (bool) $img->is_primary,
                'original_path' => $img->original_path,
            ]) ?? [],

            'header_meta' => [
                'potential_score' => $latestScore ? [
                    'score'          => $latestScore->score,
                    'classification' => $latestScore->classification,
                ] : null,
                'analyses' => $latestAnalysis ? [
                    'urgency_level' => $latestAnalysis->urgency_level,
                    'price_alert'   => $latestAnalysis->price_alert,
                    'analysis'      => $latestAnalysis->analysis,
                ] : null,
            ],
        ];
    }
}
