<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Status
            'status' => ['required', Rule::in(['draft', 'active', 'inactive', 'sold', 'available_soon'])],

            // Origin & identification
            'origin' => ['required', Rule::in(['national', 'imported'])],
            'license_plate' => ['nullable', 'string', 'max:20'],
            'vin' => ['nullable', 'string', 'max:50'],

            // Registration
            'registration_month' => ['nullable', 'integer', 'between:1,12'],
            'registration_year' => ['required', 'integer', 'min:1900', 'max:' . now()->year],

            // Core vehicle data
            'car_brand_id' => ['required', 'exists:car_brands,id'],
            'car_model_id' => ['required', 'exists:car_models,id'],
            'version' => ['required', 'string', 'max:150'],
            'public_version_name' => ['nullable', 'string', 'max:150'],
            'fuel_type' => ['required', 'string', 'max:50'],
            'power_hp' => ['required', 'integer', 'min:1', 'max:2000'],
            'engine_capacity_cc' => ['required', 'integer', 'min:1', 'max:10000'],
            'doors' => ['required', 'integer', 'min:1', 'max:6'],
            'transmission' => ['required', 'string', 'max:50'],

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
            'service_records' => ['nullable', 'string', 'max:10'],
            'has_spare_key' => ['boolean'],
            'has_manuals' => ['boolean'],

            // Pricing
            'price_gross' => ['nullable', 'numeric', 'min:0'],
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

            // Imagens normais (upload)
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB cada

            // Metadados opcionais das imagens normais (mesma ordem do array images)
            'images_meta' => ['nullable', 'array'],
            'images_meta.*.is_primary' => ['nullable', 'boolean'],
            'images_meta.*.order' => ['nullable', 'integer', 'min:1'],

            // Imagens 360 exterior (upload)
            'exterior_360_images' => ['nullable', 'array'],
            'exterior_360_images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB cada

            // Metadados opcionais das imagens 360 (mesma ordem do array exterior_360_images)
            'exterior_360_meta' => ['nullable', 'array'],
            'exterior_360_meta.*.order' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
