<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ficha de impressão A4 de viatura (2026-06-27).
 *
 * Endpoint próprio (`GET /cars/{id}/print-sheet`) em vez de estender
 * `CarSpecsResource` — a Ficha interna foi optimizada em H2b (dívida 9)
 * para evitar over-fetching, e a ficha de impressão precisa de MUITO mais
 * dados (`vehicle_attributes` completo + Company + warranty + category).
 * Mesmo padrão dos endpoints separados do dashboard (V1+V2/V3).
 *
 * O flattening dos atributos de habitação/exterior/etc é feito no FRONTEND
 * (usa `helpers/labels.ts` + `pages/Cars/Car/data/vehicleAttributes.ts` para
 * traduções e labels de cama). Aqui devolve-se o `vehicle_attributes` em
 * shape normalizado (via accessor `Car::vehicle_attributes` que corre por
 * `VehicleAttribute::normalizeShape` — fonte única de verdade da estrutura).
 *
 * PII: matrícula e VIN emitidos aqui — endpoint é interno e tenant-scoped
 * como o /specs. NÃO usar esta Resource em endpoints públicos.
 */
class CarPrintSheetResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var \App\Models\Car $this */
        $va      = $this->vehicle_attributes ?? [];
        $company = $this->company; // eager loaded no Controller
        $brand   = $this->brand;
        $model   = $this->model;
        $category = $this->category ?? null;

        return [
            // ── Empresa (para cabeçalho + rodapé) ─────────────────────────
            'company' => [
                'trade_name'   => $company?->trade_name,
                'fiscal_name'  => $company?->fiscal_name,
                'logo_path'    => $company?->logo_path, // FE prefixa REACT_APP_PUBLIC_URL
                'address'      => $company?->address,
                'postal_code'  => $company?->postal_code,
                'phone'        => $company?->phone,
                'mobile'       => $company?->mobile,
                'email'        => $company?->email,
                'website'      => $company?->website,
            ],

            // ── Identificação da viatura ──────────────────────────────────
            'vehicle_type' => $this->vehicle_type,
            'brand'        => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'model'        => $model ? ['id' => $model->id, 'name' => $model->name] : null,
            'category'     => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
            'version'      => $this->public_version_name ?: $this->version,
            'engine_brand' => $this->engine_brand,
            'license_plate'=> $this->license_plate,
            'vin'          => $this->vin,

            // ── Registo ───────────────────────────────────────────────────
            'registration' => [
                'year'  => $this->registration_year,
                'month' => $this->registration_month,
            ],

            // ── Preços (FE aplica regra "Sob consulta" com hide_price_online) ──
            'price' => [
                'gross'             => $this->price_gross ? (float) $this->price_gross : null,
                'promo_gross'       => $this->promo_price_gross ? (float) $this->promo_price_gross : null,
                'hide_price_online' => (bool) $this->hide_price_online,
            ],

            // ── Faixa de dados-chave ──────────────────────────────────────
            'headline_stats' => [
                'seats'          => $this->seats,
                'sleeps'         => $va['habitation_basics']['sleeps'] ?? null,
                'length_m'       => isset($va['dimensions']['length_m']) ? (float) $va['dimensions']['length_m'] : null,
                'gross_weight_kg'=> isset($va['weights']['gross_weight_kg']) ? (int) $va['weights']['gross_weight_kg'] : null,
                'mileage_km'     => $this->mileage_km !== null ? (int) $this->mileage_km : null,
            ],

            // ── Motor + estado ────────────────────────────────────────────
            'specs' => [
                'fuel_type'          => $this->fuel_type,
                'transmission'       => $this->transmission,
                'power_hp'           => $this->power_hp,
                'engine_capacity_cc' => $this->engine_capacity_cc,
                'doors'              => $this->doors,
                'segment'            => $this->segment,
                'exterior_color'     => $this->exterior_color,
                'is_metallic'        => (bool) $this->is_metallic,
                'interior_color'     => $this->interior_color,
            ],
            'state' => [
                'condition'     => $this->condition,
                'origin'        => $this->origin,
                'has_spare_key' => (bool) $this->has_spare_key,
                'has_manuals'   => (bool) $this->has_manuals,
                'is_trade_in'   => (bool) $this->is_resume,
            ],

            // ── Garantia (Sub-fase F, 1.14.3) ─────────────────────────────
            'warranty_months' => $this->warranty_months !== null ? (int) $this->warranty_months : null,

            // ── Atributos completos (FE renderiza os 9 grupos) ────────────
            // Shape completo do accessor Car::vehicle_attributes (passa por
            // VehicleAttribute::normalizeShape) — inclui habitation_basics
            // (kitchen+bathroom), energy_climate, exterior, security,
            // chassis_structure, interior_furniture, living_room, beds.
            'vehicle_attributes' => $va,

            // ── Extras (autocaravana pode ter poucos, mas alguns caem aqui
            //     como "Rádio", "Ar condicionado", etc.) ─────────────────
            'extras' => $this->extras ?? [],
        ];
    }
}
