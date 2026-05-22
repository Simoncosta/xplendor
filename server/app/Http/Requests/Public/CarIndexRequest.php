<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Pagination / ordering
            'perPage'        => ['nullable', 'integer', 'min:1', 'max:100'],
            'orderBy'        => ['nullable', 'string', Rule::in(['created_at', 'price_gross', 'registration_year', 'mileage_km'])],
            'orderDirection' => ['nullable', 'string', Rule::in(['asc', 'desc'])],

            // Legacy filters
            'doors'              => ['nullable', 'integer'],
            'condition'          => ['nullable', 'string', Rule::in(['new', 'used'])],
            'min_price_gross'    => ['nullable', 'numeric', 'min:0'],
            'max_price_gross'    => ['nullable', 'numeric', 'min:0'],
            'exterior_colors'    => ['nullable', 'array'],
            'exterior_colors.*'  => ['string'],
            'interior_colors'    => ['nullable', 'array'],
            'interior_colors.*'  => ['string'],
            'registration_years'   => ['nullable', 'array'],
            'registration_years.*' => ['integer'],
            'fuel_types'         => ['nullable', 'array'],
            'fuel_types.*'       => ['string'],
            'transmissions'      => ['nullable', 'array'],
            'transmissions.*'    => ['string'],
            'vehicle_type'       => ['nullable', 'string', Rule::in(['car', 'motorcycle', 'motorhome', 'caravan'])],
            'segment'            => ['nullable', 'string'],
            'brand'              => ['nullable', 'string'],
            'model'              => ['nullable', 'string'],

            // Habitation filters (motorhome / caravan)
            'category'        => ['nullable', 'string'],
            'bed_types'       => ['nullable', 'array'],
            'bed_types.*'     => ['string', Rule::in([
                'camas_gemeas', 'cama_central', 'cama_francesa', 'cama_basculante',
                'cama_capucino', 'cama_garagem', 'beliche', 'cama_transversal',
                'cama_elevatoria_eletrica', 'cama_suspensa', 'cama_convertivel',
                'outra', 'cama_rebativel_cabine',
            ])],
            'min_seats'       => ['nullable', 'integer', 'min:1'],
            'max_seats'       => ['nullable', 'integer', 'min:1'],
            'min_length_m'    => ['nullable', 'numeric', 'min:0'],
            'max_length_m'    => ['nullable', 'numeric', 'min:0'],
            'has_bathroom'    => ['nullable', 'boolean'],
            'has_kitchen'     => ['nullable', 'boolean'],
            'has_solar_panel' => ['nullable', 'boolean'],
        ];
    }
}
