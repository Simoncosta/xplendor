<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarLeadRequest extends FormRequest
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
            'car_id'    => ['required', 'exists:cars,id'],
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:255'],
            'mensagem'  => ['nullable', 'string'],
        ];
    }
}
