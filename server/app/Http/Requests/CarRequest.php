<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarRequest extends FormRequest
{
    /**
     * M2.2 — marcas de motor aceites (autocaravanas e caravanas).
     * A Matilde confirmou: a autocaravana é Challenger/McLouis, o MOTOR
     * por baixo é Fiat/Renault/Ford/etc. (não confundir com
     * `vehicle_attributes.chassis_structure.chassis_type`, que é o TIPO de
     * chassis — standard/alko/other — atributo de habitação).
     * Constante extensível: novas marcas entram com 1 linha + sem migration
     * (string nullable na BD).
     */
    public const VALID_ENGINE_BRANDS = [
        'Fiat', 'Renault', 'Ford', 'Citroën', 'Mercedes', 'Iveco',
        'Peugeot', 'VW',
    ];

    protected function prepareForValidation(): void
    {
        $vehicleType = $this->input('vehicle_type') ?: 'car';

        $payload = [
            'vehicle_type' => $vehicleType,
            'subsegment' => null,
        ];

        if ($vehicleType === 'caravan') {
            $payload['fuel_type'] = null;
            $payload['engine_capacity_cc'] = null;
            $payload['power_hp'] = null;
            $payload['transmission'] = null;
        }

        $this->merge($payload);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "Guardar rascunho" (R1, 2026-06-22) — campos top-level que devem ser
     * obrigatórios em publicação MAS opcionais em rascunho. Inclui os 4
     * `required_unless:vehicle_type,caravan` do motor.
     *
     * Convenção: adicionar campo novo `required` (ou `required_unless`) à
     * regra principal? Acrescenta a chave aqui também, senão o rascunho
     * passa a rejeitar a sua ausência (defeats the purpose).
     */
    private const FIELDS_RELAXED_IN_DRAFT = [
        'origin',
        'registration_year',
        'car_brand_id',
        'car_model_id',
        'version',
        'doors',
        'segment',
        'seats',
        'exterior_color',
        'condition',
        'fuel_type',
        'power_hp',
        'engine_capacity_cc',
        'transmission',
    ];

    /**
     * Detecta se este request é uma gravação como rascunho. Dispara em DOIS
     * casos (qualquer um chega):
     *  (a) flag explícita `__save_as_draft=1` no payload (botão "Guardar
     *      rascunho" no FE);
     *  (b) `status === 'draft'` no payload (utilizador escolheu Rascunho no
     *      <Select> e gravou com o botão normal).
     *
     * Os 2 caminhos chegam ao mesmo resultado — a Matilde pode esquecer-se
     * do dropdown e o botão dedicado força status='draft' antes do submit.
     */
    private function isDraftSave(): bool
    {
        return $this->boolean('__save_as_draft')
            || $this->input('status') === 'draft';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->baseRules();

        if ($this->isDraftSave()) {
            return $this->relaxForDraft($rules);
        }

        return $rules;
    }

    /**
     * Relaxa apenas o "obrigatório" de cada campo da allow-list — preserva
     * todas as regras de TIPO/FORMATO (`integer`, `min:1`, `max:10`, `exists:`,
     * `Rule::in(...)`, etc.).
     *
     * Lógica: campo VAZIO num rascunho passa; campo PREENCHIDO com lixo é
     * rejeitado na mesma. "Relaxar obrigatório" ≠ "aceitar tudo".
     */
    private function relaxForDraft(array $rules): array
    {
        foreach (self::FIELDS_RELAXED_IN_DRAFT as $key) {
            if (!isset($rules[$key])) {
                continue;
            }
            $rules[$key] = $this->stripRequiredKeepNullable($rules[$key]);
        }

        // vehicle_type: único obrigatório no rascunho. Já vinha como
        // ['nullable', Rule::in(...)] na regra base (prepareForValidation
        // injecta default 'car'); aqui forçamos explicit required + enum.
        $rules['vehicle_type'] = ['required', Rule::in(['car', 'motorcycle', 'motorhome', 'caravan'])];

        return $rules;
    }

    /**
     * Remove `required` e `required_unless:*` do array de regras; garante
     * que `nullable` está presente. As restantes regras (`integer`, `min`,
     * `max`, `exists`, etc.) ficam intactas.
     */
    private function stripRequiredKeepNullable(array $fieldRules): array
    {
        $filtered = array_values(array_filter(
            $fieldRules,
            static function ($rule) {
                if (!is_string($rule)) {
                    return true; // Rule::in(...) e outros objects
                }
                if ($rule === 'required') {
                    return false;
                }
                return !str_starts_with($rule, 'required_unless')
                    && !str_starts_with($rule, 'required_if')
                    && !str_starts_with($rule, 'required_with')
                    && !str_starts_with($rule, 'required_without');
            }
        ));

        if (!in_array('nullable', $filtered, true)) {
            array_unshift($filtered, 'nullable');
        }

        return $filtered;
    }

    /**
     * Regras completas — a defesa que estava aqui antes da R1. Idempotente.
     *
     * @return array<string, mixed>
     */
    private function baseRules(): array
    {
        return [
            // Status
            'status' => ['required', Rule::in(['draft', 'active', 'inactive', 'sold', 'available_soon', 'reserved'])],
            'sold_at' => ['nullable', 'date'],

            // Origin & identification
            'origin' => ['required', Rule::in(['national', 'imported'])],
            'license_plate' => ['nullable', 'string', 'max:20'],
            'vin' => ['nullable', 'string', 'max:50'],

            // Registration
            'registration_month' => ['nullable', 'integer', 'between:1,12'],
            'registration_year' => ['required', 'integer', 'min:1900', 'max:' . now()->year],

            // Core vehicle data
            'vehicle_type' => ['nullable', Rule::in(['car', 'motorcycle', 'motorhome', 'caravan'])],
            'subsegment' => ['nullable', 'string', 'max:50'],
            'car_category_id' => ['nullable', 'exists:car_categories,id'],
            'vehicle_attributes'                              => ['nullable', 'array'],
            'vehicle_attributes.dimensions'                   => ['nullable', 'array'],
            'vehicle_attributes.dimensions.length_m'          => ['nullable', 'numeric', 'min:0.1', 'max:30'],
            'vehicle_attributes.dimensions.width_m'           => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'vehicle_attributes.dimensions.height_m'          => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'vehicle_attributes.weights'                      => ['nullable', 'array'],
            'vehicle_attributes.weights.gross_weight_kg'      => ['nullable', 'integer', 'min:100', 'max:10000'],
            'vehicle_attributes.habitation_basics'                              => ['nullable', 'array'],
            'vehicle_attributes.habitation_basics.sleeps'                      => ['nullable', 'integer', 'min:1', 'max:12'],
            'vehicle_attributes.habitation_basics.has_bathroom'                => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.has_kitchen'                 => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen'                     => ['nullable', 'array'],
            'vehicle_attributes.habitation_basics.kitchen.has_stove'           => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.has_oven'            => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.has_extending_counter' => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.has_microwave'       => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.has_extractor'       => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.has_fridge'          => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.kitchen.fridge_type'         => ['nullable', Rule::in(['trivalent', 'compressor', 'absorption', 'none'])],
            'vehicle_attributes.habitation_basics.kitchen.fridge_litres'       => ['nullable', 'integer', 'min:0', 'max:500'],
            'vehicle_attributes.habitation_basics.kitchen.fridge_shelves'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'vehicle_attributes.habitation_basics.bathroom'                    => ['nullable', 'array'],
            'vehicle_attributes.habitation_basics.bathroom.has_toilet'         => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.bathroom.has_shower'         => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.bathroom.shower_type'        => ['nullable', Rule::in(['separate', 'independent', 'combined'])],
            'vehicle_attributes.habitation_basics.bathroom.clean_water_litres' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'vehicle_attributes.habitation_basics.bathroom.waste_water_litres' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // 2026-06-29 — separar "tem depósito" de "quantos litros": a cliente
            // pode saber que tem sem saber a capacidade. Retro-compat garantida
            // no FE (`normalizeVehicleAttributes` marca a checkbox quando os
            // litros > 0 mas o campo ainda não existe no JSON).
            'vehicle_attributes.habitation_basics.bathroom.has_clean_water_tank' => ['nullable', 'boolean'],
            'vehicle_attributes.habitation_basics.bathroom.has_waste_water_tank' => ['nullable', 'boolean'],
            'vehicle_attributes.beds'                                          => ['nullable', 'array'],
            'vehicle_attributes.beds.*.type'                                   => ['nullable', Rule::in([
                'camas_gemeas',
                'cama_central',
                'cama_francesa',
                'cama_basculante',
                'cama_capucino',
                'cama_garagem',
                'beliche',
                'cama_transversal',
                'cama_elevatoria_eletrica',
                'cama_suspensa',
                'cama_convertivel',
                'cama_sofa',
                'outra',
                'cama_rebativel_cabine',
            ])],
            // M2.1 — capacity por cama (1-4); frontend envia o cálculo, validação é defesa.
            'vehicle_attributes.beds.*.capacity'                               => ['nullable', 'integer', 'min:1', 'max:4'],
            'vehicle_attributes.autonomy_km'                                   => ['nullable', 'integer', 'min:0', 'max:5000'],

            // B2 — Energia e Aquecimento
            'vehicle_attributes.energy_climate'                                         => ['nullable', 'array'],
            'vehicle_attributes.energy_climate.water_heater_source'                     => ['nullable', Rule::in(['electric', 'gas', 'diesel', 'none'])],
            'vehicle_attributes.energy_climate.water_heater_brand'                      => ['nullable', 'string', 'max:100'],
            'vehicle_attributes.energy_climate.ambient_heating_source'                  => ['nullable', Rule::in(['electric', 'gas', 'diesel', 'none'])],
            'vehicle_attributes.energy_climate.ambient_heating_brand'                   => ['nullable', 'string', 'max:100'],
            'vehicle_attributes.energy_climate.has_solar_panel'                         => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.solar_panel_count'                       => ['nullable', 'integer', 'min:1', 'max:10'],
            'vehicle_attributes.energy_climate.solar_panel_watts'                       => ['nullable', 'integer', 'min:0', 'max:5000'],
            'vehicle_attributes.energy_climate.has_inverter'                            => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.inverter_type'                           => ['nullable', Rule::in(['pure_sine', 'modified_sine'])],
            'vehicle_attributes.energy_climate.inverter_watts'                          => ['nullable', 'integer', 'min:0', 'max:10000'],
            'vehicle_attributes.energy_climate.has_gpl'                                 => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.has_battery_cutoff'                      => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.gpl_bottles_count'                       => ['nullable', 'integer', 'min:0', 'max:10'],
            'vehicle_attributes.energy_climate.has_generator'                           => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.has_external_power_socket'               => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.battery_count'                           => ['nullable', 'integer', 'min:0', 'max:10'],
            'vehicle_attributes.energy_climate.cabin_battery_count'                     => ['nullable', 'integer', 'min:0', 'max:5'],
            'vehicle_attributes.energy_climate.cell_battery_count'                      => ['nullable', 'integer', 'min:0', 'max:10'],
            // 2026-06-29 — Baterias de lítio (quantidade + capacidade Ah).
            // Padrão SEM checkbox pai igual às baterias existentes: null = "não tem".
            'vehicle_attributes.energy_climate.lithium_battery_count'                   => ['nullable', 'integer', 'min:0', 'max:20'],
            'vehicle_attributes.energy_climate.lithium_battery_ah'                      => ['nullable', 'integer', 'min:0', 'max:2000'],
            // Lote 3 — A/C 220V de habitação (boolean + marca opcional; padrão
            // has_awning + awning_brand) + VIESA (boolean simples).
            'vehicle_attributes.energy_climate.has_aircon_220v'                         => ['nullable', 'boolean'],
            'vehicle_attributes.energy_climate.aircon_220v_brand'                       => ['nullable', 'string', 'max:100'],
            'vehicle_attributes.energy_climate.has_viesa'                               => ['nullable', 'boolean'],

            // B2 — Exterior
            'vehicle_attributes.exterior'                                               => ['nullable', 'array'],
            'vehicle_attributes.exterior.has_awning'                                    => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.awning_brand'                                  => ['nullable', 'string', 'max:100'],
            'vehicle_attributes.exterior.has_national_antenna'                          => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_parabolic_antenna'                         => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_bike_rack'                                 => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_motorbike_rack'                            => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_electric_step'                             => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_manual_step'                               => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_stabilizers'                               => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_spare_wheel'                               => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_fix_n_go_kit'                              => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_bull_eye'                                  => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_external_wc'                               => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_hubcaps'                                   => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.has_external_ladder'                           => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.garage'                                        => ['nullable', 'array'],
            'vehicle_attributes.exterior.garage.has_garage'                             => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.garage.has_double_opening'                     => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.garage.is_spacious'                            => ['nullable', 'boolean'],
            'vehicle_attributes.exterior.garage.has_height_adjuster'                    => ['nullable', 'boolean'],

            // B2 — Segurança e Fechaduras
            'vehicle_attributes.security'                                               => ['nullable', 'array'],
            'vehicle_attributes.security.has_alarm'                                     => ['nullable', 'boolean'],
            'vehicle_attributes.security.has_hatch_lock'                                => ['nullable', 'boolean'],
            'vehicle_attributes.security.has_cabin_lock'                                => ['nullable', 'boolean'],
            'vehicle_attributes.security.has_safe_door'                                 => ['nullable', 'boolean'],
            'vehicle_attributes.security.has_gas_lock'                                  => ['nullable', 'boolean'],
            'vehicle_attributes.security.has_entry_door_lock'                           => ['nullable', 'boolean'],
            'vehicle_attributes.security.other_locks_notes'                             => ['nullable', 'string', 'max:500'],

            // B2 — Chassis e Estrutura
            'vehicle_attributes.chassis_structure'                                      => ['nullable', 'array'],
            'vehicle_attributes.chassis_structure.chassis_type'                         => ['nullable', Rule::in(['standard', 'alko', 'other'])],
            'vehicle_attributes.chassis_structure.chassis_notes'                        => ['nullable', 'string', 'max:500'],
            'vehicle_attributes.chassis_structure.has_turbovent_skylight'               => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_panoramic_skylight'               => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_40x40_skylight'                   => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.other_skylights_notes'                => ['nullable', 'string', 'max:500'],
            'vehicle_attributes.chassis_structure.has_air_suspension'                   => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_air_suspension_compressor'        => ['nullable', 'boolean'],
            // Lote 3 — Rodado duplo.
            'vehicle_attributes.chassis_structure.has_dual_rear_wheel'                  => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_remifront'                        => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_window_blackouts'                 => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_mosquito_nets'                    => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_door_mosquito_net'                => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.has_cabin_blackouts'                  => ['nullable', 'boolean'],
            'vehicle_attributes.chassis_structure.cabin_blackout_type'                  => ['nullable', 'string', 'max:100'],

            // B2 — Mobiliário Interior
            'vehicle_attributes.interior_furniture'                                     => ['nullable', 'array'],
            'vehicle_attributes.interior_furniture.has_foldable_table'                  => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_rotating_seats'                  => ['nullable', 'boolean'],
            // Lote 3 — `excellent` adicionado (qualidade decrescente: excellent > good > fair > worn).
            'vehicle_attributes.interior_furniture.upholstery_state'                    => ['nullable', Rule::in(['excellent', 'good', 'fair', 'worn', 'replaced'])],
            'vehicle_attributes.interior_furniture.has_curtains'                        => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_led_lighting'                    => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_halo_lighting'                   => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_tv_support'                      => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_tv'                              => ['nullable', 'boolean'],
            // 2026-06-29 — TVs: quantidade + localização (texto livre, decisão
            // Matilde: quer descrever livremente onde estão as TVs). Ambas
            // condicionais no UI a has_tv=true.
            'vehicle_attributes.interior_furniture.tv_count'                            => ['nullable', 'integer', 'min:0', 'max:10'],
            'vehicle_attributes.interior_furniture.tv_location'                         => ['nullable', 'string', 'max:255'],
            'vehicle_attributes.interior_furniture.has_command_panel'                   => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_water_infiltrations'             => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.has_wardrobe'                        => ['nullable', 'boolean'],
            'vehicle_attributes.interior_furniture.infiltrations_notes'                 => ['nullable', 'string', 'max:500'],

            // Sala (M5)
            'vehicle_attributes.living_room'                                             => ['nullable', 'array'],
            'vehicle_attributes.living_room.layout'                                     => ['nullable', Rule::in(['face_to_face', 'l_shape', 'panoramic', 'double_dinette'])],
            'vehicle_attributes.living_room.has_extending_table'                        => ['nullable', 'boolean'],

            'car_brand_id' => ['required', 'exists:car_brands,id'],
            'car_model_id' => ['required', 'exists:car_models,id'],
            'version' => ['required', 'string', 'max:150'],
            'public_version_name' => ['nullable', 'string', 'max:150'],
            'engine_brand'        => ['nullable', 'string', Rule::in(self::VALID_ENGINE_BRANDS)],
            'fuel_type' => ['nullable', 'required_unless:vehicle_type,caravan', 'string', 'max:50'],
            'power_hp' => ['nullable', 'required_unless:vehicle_type,caravan', 'integer', 'min:1', 'max:2000'],
            'engine_capacity_cc' => ['nullable', 'required_unless:vehicle_type,caravan', 'integer', 'min:1', 'max:10000'],
            'doors' => ['required', 'integer', 'min:1', 'max:6'],
            'transmission' => ['nullable', 'required_unless:vehicle_type,caravan', 'string', 'max:50'],

            // Details
            'segment' => ['required', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'min:1', 'max:10'],
            'exterior_color' => ['required', 'string', 'max:50'],
            'is_metallic' => ['boolean'],
            'interior_color' => ['nullable', 'string', 'max:50'],
            'condition' => ['required', Rule::in(['new', 'used', 'like_new', 'good', 'service', 'trade_in', 'classic'])],
            'mileage_km' => ['nullable', 'integer', 'min:0'],

            // Additional data
            'co2_emissions' => ['nullable', 'integer', 'min:0'],
            'toll_class' => ['nullable', 'string', 'max:50'],
            'cylinders' => ['nullable', 'integer', 'min:1', 'max:16'],
            'warranty_available' => ['nullable', 'string', 'max:50'],
            'warranty_due_date' => ['nullable', 'date'],
            'warranty_km' => ['nullable', 'integer', 'min:0'],
            // Sub-fase F (Lote Matilde 3) — duração em meses. Cap 180 (15 anos) corta
            // erros de digitação evidentes sem bloquear garantias longas reais.
            'warranty_months' => ['nullable', 'integer', 'min:1', 'max:180'],
            'service_records' => ['nullable', 'string', 'max:10'],
            'has_spare_key' => ['boolean'],
            'has_manuals' => ['boolean'],

            // Pricing
            'price_gross' => ['nullable', 'numeric', 'min:0'],
            'promo_price_gross' => ['nullable', 'numeric', 'min:0'],
            'price_net' => ['nullable', 'numeric', 'min:0'],
            'hide_price_online' => ['boolean'],
            'monthly_payment' => ['nullable', 'numeric', 'min:0'],

            // Extras
            'extras' => ['nullable', 'array'],
            'lifestyle' => ['nullable', 'array'],

            // Advertiser content
            'description_website_pt' => ['nullable', 'string'],
            'description_website_en' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'youtube_url' => ['nullable', 'url'],
            'seller_user_id' => ['nullable', 'exists:users,id'],

            // Imagens normais (upload)
            'images' => ['nullable', 'array', 'max:60'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'], // 10MB cada

            // Imagens já existentes
            // existing_images_present=1 indica que a lista foi enviada intencionalmente
            // (mesmo que vazia) — necessário porque FormData não representa arrays vazios.
            'existing_images_present' => ['nullable', 'boolean'],
            'existing_images' => ['nullable', 'array'],
            'existing_images.*' => ['string'],

            // Metadados opcionais das imagens normais (mesma ordem do array images)
            'images_meta'                => ['nullable', 'array'],
            'images_meta.*.is_primary'   => ['nullable', 'boolean'],
            'images_meta.*.order'        => ['nullable', 'integer', 'min:1'],
            'images_meta.*.crop'         => ['nullable', 'array'],
            'images_meta.*.crop.x'       => ['nullable', 'integer', 'min:0'],
            'images_meta.*.crop.y'       => ['nullable', 'integer', 'min:0'],
            'images_meta.*.crop.width'   => ['nullable', 'integer', 'min:1'],
            'images_meta.*.crop.height'  => ['nullable', 'integer', 'min:1'],

            // Imagens 360 exterior (upload)
            'exterior_360_images' => ['nullable', 'array', 'max:60'],
            'exterior_360_images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],

            // Metadados opcionais das imagens 360 (mesma ordem do array exterior_360_images)
            'exterior_360_meta' => ['nullable', 'array'],
            'exterior_360_meta.*.order' => ['nullable', 'integer', 'min:1'],

            'existing_images_meta' => ['nullable', 'array'],
            'existing_images_meta.*.is_primary' => ['nullable', 'boolean'],
            'existing_images_meta.*.order' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
