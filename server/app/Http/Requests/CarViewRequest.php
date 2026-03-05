<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CarViewRequest extends FormRequest
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
            // relations
            'car_id'     => ['required', 'integer', 'exists:cars,id'],

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
        ];
    }
}
