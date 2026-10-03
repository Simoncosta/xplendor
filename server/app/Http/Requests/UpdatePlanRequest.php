<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    /**
     * Planos são da plataforma: só o root os altera.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'root';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'string|max:255',
            'description' => 'string',
            'price' => 'numeric|min:0',
            'car_limit' => 'numeric|min:0',
            'features' => 'string',
        ];
    }
}
