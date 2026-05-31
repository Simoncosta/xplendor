<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\VehicleAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing Car API Resource.
 *
 * Transforms the Car model into a consumer-safe payload.
 * Omitted fields: internal_notes, vin, license_plate, company_id, carmine_id,
 * seller_user_id, car_brand_id, car_model_id, car_category_id, lifestyle, price_net,
 * and the raw `seller` relation.
 *
 * vehicle_attributes is flattened into specs / habitation / features / beds.
 * habitation, features, beds, and category are null for non-motorhome/caravan types.
 *
 * See RESOURCE_SHAPE.md in this directory for the full documented shape.
 */
class CarPublicResource extends JsonResource
{
    /** @var array<string, string> */
    private const BED_LABELS = [
        'camas_gemeas'             => 'Camas gémeas',
        'cama_central'             => 'Cama central',
        'cama_francesa'            => 'Cama francesa',
        'cama_basculante'          => 'Cama basculante',
        'cama_capucino'            => 'Cama capucino',
        'cama_garagem'             => 'Cama de garagem',
        'beliche'                  => 'Beliche',
        'cama_transversal'         => 'Cama transversal',
        'cama_elevatoria_eletrica' => 'Cama elevatória eléctrica',
        'cama_suspensa'            => 'Cama suspensa',
        'cama_convertivel'         => 'Cama convertível de mesa',
        'outra'                    => 'Outra',
        'cama_rebativel_cabine'    => 'Rebatível na cabine',
    ];

    public function toArray(Request $request): array
    {
        $va = VehicleAttribute::normalizeShape(
            is_array($this->vehicle_attributes) ? $this->vehicle_attributes : null
        );

        $isHabitation = in_array($this->vehicle_type, ['motorhome', 'caravan'], true);

        return [
            // --- Identificadores ---
            'id'         => $this->id,
            'created_at' => $this->created_at,

            // --- Identidade ---
            'title' => $this->public_version_name ?: $this->version,
            'brand' => $this->relationLoaded('brand') && $this->brand ? [
                'id'   => $this->brand->id,
                'name' => $this->brand->name,
            ] : null,
            'model' => $this->relationLoaded('model') && $this->model ? [
                'id'   => $this->model->id,
                'name' => $this->model->name,
            ] : null,
            'category' => $this->vehicle_type === 'motorhome'
                && $this->relationLoaded('category')
                && $this->category
                ? [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ]
                : null,

            // --- Tipo e estado ---
            'vehicle_type' => $this->vehicle_type,
            'subsegment'   => $this->subsegment,
            'condition'    => $this->condition,
            'status'       => $this->status,

            // --- Preços ---
            'price_gross'       => $this->price_gross !== null ? (float) $this->price_gross : null,
            'price_promo'       => $this->promo_price_gross !== null ? (float) $this->promo_price_gross : null,
            'has_promo_price'   => (bool) $this->has_promo_price,
            'hide_price_online' => (bool) $this->hide_price_online,
            'monthly_payment'   => $this->monthly_payment !== null ? (float) $this->monthly_payment : null,

            // --- Específicos ---
            'year'               => $this->registration_year,
            'registration_month' => $this->registration_month,
            'mileage_km'         => $this->mileage_km,
            'fuel_type'          => $this->fuel_type,
            'transmission'       => $this->transmission,
            'doors'              => $this->doors,
            'power_hp'           => $this->power_hp,
            'engine_capacity_cc' => $this->engine_capacity_cc,
            'cylinders'          => $this->cylinders,
            'co2_emissions'      => $this->co2_emissions,
            'toll_class'         => $this->toll_class,

            // --- Cores ---
            'exterior_color' => $this->exterior_color,
            'is_metallic'    => (bool) $this->is_metallic,
            'interior_color' => $this->interior_color,

            // --- Origem / garantia ---
            'origin'             => $this->origin,
            'is_trade_in'        => (bool) $this->is_resume,
            'warranty_available' => $this->warranty_available,
            'warranty_due_date'  => $this->warranty_due_date,
            'warranty_km'        => $this->warranty_km,
            'service_records'    => $this->service_records,
            'has_spare_key'      => (bool) $this->has_spare_key,
            'has_manuals'        => (bool) $this->has_manuals,

            // --- Atributos achatados (vehicle_attributes → specs / habitation / features / beds) ---
            'specs'     => $this->buildSpecs($va),
            'habitation' => $isHabitation ? $this->buildHabitation($va) : null,
            'features'   => $isHabitation ? $this->buildFeatures($va) : null,
            'beds'       => $isHabitation ? $this->buildBeds($va) : null,

            // --- Editorial ---
            'description_pt' => $this->description_website_pt,
            'description_en' => $this->description_website_en,
            'youtube_url'    => $this->youtube_url,

            // --- Extras (equipamento) ---
            'extras' => $this->extras ?? [],

            // --- Imagens ---
            'images'          => $this->buildImages(),
            'external_images' => $this->buildExternalImages(),

            // --- Vendedor (apenas campos seguros — email, role, etc. omitidos) ---
            'seller' => $this->seller_contact ? [
                'name'     => $this->seller_contact['name'] ?? null,
                'avatar'   => $this->seller_contact['avatar'] ?? null,
                'mobile'   => $this->seller_contact['mobile'] ?? null,
                'whatsapp' => $this->seller_contact['whatsapp'] ?? null,
            ] : null,
        ];
    }

    /** Core dimensions + weight from vehicle_attributes, plus seats from cars table. */
    private function buildSpecs(array $va): array
    {
        $specs = ['seats' => $this->seats];

        $dim = $va['dimensions'] ?? [];
        if (isset($dim['length_m']) && $dim['length_m'] !== null) {
            $specs['length_m'] = (float) $dim['length_m'];
        }
        if (isset($dim['width_m']) && $dim['width_m'] !== null) {
            $specs['width_m'] = (float) $dim['width_m'];
        }
        if (isset($dim['height_m']) && $dim['height_m'] !== null) {
            $specs['height_m'] = (float) $dim['height_m'];
        }

        $weights = $va['weights'] ?? [];
        if (isset($weights['gross_weight_kg']) && $weights['gross_weight_kg'] !== null) {
            $specs['gross_weight_kg'] = (int) $weights['gross_weight_kg'];
        }

        if (isset($va['autonomy_km']) && $va['autonomy_km'] !== null) {
            $specs['autonomy_km'] = (int) $va['autonomy_km'];
        }

        return $specs;
    }

    /**
     * Habitation basics (kitchen + bathroom) — motorhome/caravan only.
     * Only keys with explicit values are included (absent keys omitted).
     */
    private function buildHabitation(array $va): array
    {
        $hb      = $va['habitation_basics'] ?? [];
        $kitchen = $hb['kitchen'] ?? [];
        $bath    = $hb['bathroom'] ?? [];
        $result  = [];

        foreach (['has_kitchen', 'has_bathroom'] as $key) {
            if (isset($hb[$key])) {
                $result[$key] = (bool) $hb[$key];
            }
        }

        $boolKitchen = ['has_stove', 'has_oven', 'has_microwave', 'has_extractor', 'has_fridge'];
        foreach ($boolKitchen as $key) {
            if (isset($kitchen[$key])) {
                $result[$key] = (bool) $kitchen[$key];
            }
        }
        if (isset($kitchen['fridge_litres'])) {
            $result['fridge_litres'] = (int) $kitchen['fridge_litres'];
        }
        if (!empty($kitchen['fridge_type'])) {
            $result['fridge_type'] = $kitchen['fridge_type'];
        }

        $boolBath = ['has_toilet', 'has_shower'];
        foreach ($boolBath as $key) {
            if (isset($bath[$key])) {
                $result[$key] = (bool) $bath[$key];
            }
        }
        if (!empty($bath['shower_type'])) {
            $result['shower_type'] = $bath['shower_type'];
        }
        foreach (['clean_water_litres', 'waste_water_litres'] as $key) {
            if (isset($bath[$key])) {
                $result[$key] = (int) $bath[$key];
            }
        }

        return $result;
    }

    /**
     * Filterable features from energy_climate, exterior, chassis — motorhome/caravan only.
     * Only keys with explicit values are included.
     */
    private function buildFeatures(array $va): array
    {
        $ec  = $va['energy_climate'] ?? [];
        $ext = $va['exterior'] ?? [];
        $cs  = $va['chassis_structure'] ?? [];
        $inf = $va['interior_furniture'] ?? [];
        $sec = $va['security'] ?? [];

        $result = [];

        $boolEc = ['has_solar_panel', 'has_inverter', 'has_gpl', 'has_generator', 'has_external_power_socket'];
        foreach ($boolEc as $key) {
            if (isset($ec[$key])) $result[$key] = (bool) $ec[$key];
        }
        foreach (['water_heater_source', 'ambient_heating_source'] as $key) {
            if (!empty($ec[$key])) $result[$key] = $ec[$key];
        }
        foreach (['water_heater_brand', 'ambient_heating_brand'] as $key) {
            if (!empty($ec[$key])) $result[$key] = $ec[$key];
        }
        if (isset($ec['battery_count'])) $result['battery_count'] = (int) $ec['battery_count'];

        $boolExt = ['has_awning', 'has_bike_rack', 'has_motorbike_rack', 'has_electric_step',
                    'has_manual_step', 'has_stabilizers', 'has_spare_wheel', 'has_bull_eye',
                    'has_external_wc', 'has_hubcaps', 'has_national_antenna', 'has_parabolic_antenna'];
        foreach ($boolExt as $key) {
            if (isset($ext[$key])) $result[$key] = (bool) $ext[$key];
        }
        if (!empty($ext['awning_brand'])) $result['awning_brand'] = $ext['awning_brand'];

        $boolCs = ['has_remifront', 'has_window_blackouts', 'has_mosquito_nets',
                   'has_door_mosquito_net', 'has_cabin_blackouts',
                   'has_turbovent_skylight', 'has_panoramic_skylight', 'has_40x40_skylight'];
        foreach ($boolCs as $key) {
            if (isset($cs[$key])) $result[$key] = (bool) $cs[$key];
        }
        if (!empty($cs['chassis_type'])) {
            $result['chassis_type']    = $cs['chassis_type'];
            $result['has_alko_chassis'] = $cs['chassis_type'] === 'alko';
        }

        $boolInf = ['has_foldable_table', 'has_rotating_seats', 'has_curtains',
                    'has_led_lighting', 'has_halo_lighting', 'has_tv_support',
                    'has_tv', 'has_command_panel'];
        foreach ($boolInf as $key) {
            if (isset($inf[$key])) $result[$key] = (bool) $inf[$key];
        }
        if (!empty($inf['upholstery_state'])) $result['upholstery_state'] = $inf['upholstery_state'];

        $boolSec = ['has_alarm', 'has_hatch_lock', 'has_cabin_lock', 'has_safe_door',
                    'has_gas_lock', 'has_entry_door_lock'];
        foreach ($boolSec as $key) {
            if (isset($sec[$key])) $result[$key] = (bool) $sec[$key];
        }

        return $result;
    }

    /** Beds with slug + pt-PT label — motorhome/caravan only. */
    private function buildBeds(array $va): array
    {
        $beds = $va['beds'] ?? [];
        if (empty($beds)) {
            return [];
        }

        return collect($beds)
            ->filter(fn($b) => !empty($b['type']))
            ->map(fn($b) => [
                'type'  => $b['type'],
                'label' => self::BED_LABELS[$b['type']] ?? $b['type'],
            ])
            ->values()
            ->toArray();
    }

    private function buildImages(): array
    {
        if (!$this->relationLoaded('images')) {
            return [];
        }

        return collect($this->images)
            ->sortBy([['order', 'asc'], ['id', 'asc']])
            ->map(fn($img) => [
                'url'        => $img->image,
                'is_primary' => (bool) $img->is_primary,
                'order'      => $img->order,
            ])
            ->values()
            ->toArray();
    }

    private function buildExternalImages(): array
    {
        if (!$this->relationLoaded('externalImages')) {
            return [];
        }

        return collect($this->externalImages)
            ->map(fn($img) => ['url' => $img->external_url ?? null])
            ->filter(fn($img) => $img['url'] !== null)
            ->values()
            ->toArray();
    }
}
