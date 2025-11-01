<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarRequest extends FormRequest
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
            'status' => ['required', 'in:active,pending,inactive,sold,sketch,available_soon,reserved,to_order'],
            'is_imported' => ['required', 'boolean'],
            'licence_plate' => ['nullable', 'string', 'max:10'],
            'km' => ['required', 'integer', 'min:0'],
            'vin' => ['nullable', 'string', 'max:50'],
            'month_registration' => ['required', 'in:01,02,03,04,05,06,07,08,09,10,11,12'],
            'year_registration' => ['nullable', 'digits:4', 'integer'],
            'mark' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'fuel' => ['required', 'string', 'max:50'],
            'power' => ['required', 'string', 'max:50'],
            'number_doors' => ['required', 'string', 'max:3'],
            'gearbox' => ['required', 'string', 'max:50'],
            'version' => ['required', 'string', 'max:100'],
            'segment' => ['required', 'string', 'max:100'],
            'color' => ['required', 'string', 'max:50'],
            'link_youtube' => ['nullable', 'url'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'show_price' => ['required', 'boolean'],
            'discount_type' => ['nullable', 'in:amount,percentage'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'company_id' => ['required', 'exists:companies,id'],
            'seller_id' => ['required', 'exists:users,id'],
            'created_by_id' => ['required', 'exists:users,id'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
            'primary_image_index' => ['nullable', 'integer'],
            'rotate_exterior_images' => ['nullable', 'array'],
            'rotate_exterior_images.*' => ['file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:10240'],
        ];
    }
}
