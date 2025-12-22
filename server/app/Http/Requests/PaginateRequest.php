<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaginateRequest extends FormRequest
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
            'perPage' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'perPage.integer' => 'O campo perPage deve ser um número inteiro.',
            'perPage.min'     => 'O campo perPage deve ser no mínimo 1.',
            'perPage.max'     => 'O campo perPage não pode ser maior que 100.',

            'page.integer' => 'O campo page deve ser um número inteiro.',
            'page.min'     => 'O campo page deve ser no mínimo 1.',
        ];
    }
}
