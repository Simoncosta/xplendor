<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
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
            'name'              => ['required', 'string', 'max:255'],
            'logo'              => ['nullable', 'string', 'max:255'],
            'banner_main'       => ['nullable', 'string', 'max:255'],
            'title_about'       => ['nullable', 'string', 'max:255'],
            'description_about' => ['nullable', 'string'],
            'address'           => ['nullable', 'string', 'max:255'],
            'number'            => ['nullable', 'string', 'max:50'],
            'city'              => ['nullable', 'string', 'max:100'],
            'state'             => ['nullable', 'string', 'max:100'],
            'zip_code'          => ['nullable', 'string', 'max:20'],
            'show_address'      => ['required', 'in:ray,point,hidden'],
            'plan_id'           => ['required', 'exists:plans,id'],
            'country_id'        => ['required', 'exists:countries,id'],
        ];
    }
}
