<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
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
            'name'                  => ['sometimes', 'string', 'max:255'],
            'logo'                  => ['sometimes', 'nullable', 'string', 'max:255'],
            'banner_main'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_about'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_about'     => ['sometimes', 'nullable', 'string'],
            'address'               => ['sometimes', 'nullable', 'string', 'max:255'],
            'number'                => ['sometimes', 'nullable', 'string', 'max:50'],
            'city'                  => ['sometimes', 'nullable', 'string', 'max:100'],
            'state'                 => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip_code'              => ['sometimes', 'nullable', 'string', 'max:20'],
            'show_address'          => ['sometimes', 'in:ray,point,hidden'],
            'plan_id'               => ['sometimes', 'exists:plans,id'],
            'country_id'            => ['sometimes', 'exists:countries,id'],
            'social_links'          => ['sometimes', 'array'],
            'social_links.*.type'   => ['required_with:social_links', 'string', 'in:facebook,instagram,youtube,site,whatsapp,social_x,linkedin,phone'],
            'social_links.*.value'  => ['required_with:social_links', 'string', 'max:255'],
        ];
    }
}
