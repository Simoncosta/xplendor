<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyOperationRequest extends FormRequest
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
            'day_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'opens_at' => 'required|date_format:H:i',
            'closes_at' => 'required|date_format:H:i',
        ];
    }
}
