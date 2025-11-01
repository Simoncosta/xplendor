<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarRequest extends FormRequest
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
            'status' => ['sometimes', 'in:active,pending,inactive,sold,sketch,available_soon,reserved,to_order'],
            'is_imported' => ['sometimes', 'boolean'],
            'licence_plate' => ['nullable', 'string', 'max:10'],
            'km' => ['sometimes', 'integer', 'min:0'],
            'vin' => ['nullable', 'string', 'max:50'],
            'month_registration' => ['sometimes', 'in:01,02,03,04,05,06,07,08,09,10,11,12'],
            'year_registration' => ['nullable', 'digits:4', 'integer'],
            'mark' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'fuel' => ['sometimes', 'string', 'max:50'],
            'power' => ['sometimes', 'string', 'max:50'],
            'number_doors' => ['sometimes', 'string', 'max:3'],
            'gearbox' => ['sometimes', 'string', 'max:50'],
            'version' => ['sometimes', 'string', 'max:100'],
            'segment' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'string', 'max:50'],
            'link_youtube' => ['nullable', 'url'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'show_price' => ['sometimes', 'boolean'],
            'discount_type' => ['nullable', 'in:amount,percentage'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'company_id' => ['sometimes', 'exists:companies,id'],
            'seller_id' => ['sometimes', 'exists:users,id'],
            'created_by_id' => ['sometimes', 'exists:users,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'primary_image_index' => ['nullable', 'integer'],
            'rotate_exterior_images' => ['nullable', 'array'],
            'rotate_exterior_images.*' => ['file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
        ];
    }
}
