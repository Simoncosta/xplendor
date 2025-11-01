<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarLeadRequest extends FormRequest
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
            'car_id'    => ['sometimes', 'exists:cars,id'],
            'name'      => ['sometimes', 'string', 'max:255'],
            'email'     => ['sometimes', 'email', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:255'],
            'mensagem'  => ['nullable', 'string'],
        ];
    }
}
