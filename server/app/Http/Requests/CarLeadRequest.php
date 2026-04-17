<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // normaliza email e trims básicos
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->email)),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $emailRule = app()->environment('testing') ? 'email:rfc' : 'email:rfc,dns';

        return [
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', $emailRule, 'max:255'],
            'phone'   => ['nullable', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:5000'],

            // relations
            'car_id'     => ['required', 'integer', 'exists:cars,id'],
            // 'company_id' => ['required', 'integer', 'exists:companies,id'],

            // tracking
            'referrer'     => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'channel'      => ['nullable', 'string', 'max:50'],

            'visitor_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],

            'utm_source'   => ['nullable', 'string', 'max:120'],
            'utm_medium'   => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
            'utm_content'  => ['nullable', 'string', 'max:255'],
            'utm_term'     => ['nullable', 'string', 'max:255'],
            'utm_id'       => ['nullable', 'string', 'max:255'],
            'ad_id'        => ['nullable', 'string', 'max:255'],
            'click_id'     => ['nullable', 'string', 'max:255'],

            // opcionais (se fores permitir criar lead já com source/status)
            'source' => [
                'nullable',
                Rule::in(['website_form', 'whatsapp', 'phone_call', 'manual', 'api', 'chat']),
            ],
            'status' => [
                'nullable',
                Rule::in(['new', 'contacted', 'qualified', 'won', 'lost', 'spam']),
            ],
        ];
    }
}
