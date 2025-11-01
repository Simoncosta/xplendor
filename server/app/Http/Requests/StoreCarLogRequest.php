<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarLogRequest extends FormRequest
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
            'car_id'        => ['required', 'exists:cars,id'],
            'user_id'       => ['required', 'exists:users,id'],
            'field_changed' => ['required', 'string', 'max:255'],
            'old_value'     => ['nullable', 'string', 'max:255'],
            'new_value'     => ['required', 'string', 'max:255'],
        ];
    }
}
