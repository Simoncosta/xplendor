<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Só um admin convida utilizadores, e só para a sua própria empresa.
     */
    public function authorize(): bool
    {
        // Nas rotas de empresa, a permissão utilizadores.configurar é verificada na rota (ACL:
        // app/Access/RoutePermissions.php). Sem empresa no endereço (POST /register), nunca.
        return $this->user() !== null && $this->route('id') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'avatar' => 'nullable|file|image|max:2048',
            'email' => 'required|string|email|max:255|unique:users',
            'gender' => 'nullable|string|in:male,female',
            'birthdate' => 'nullable|date',
            'mobile' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
        ];
    }
}
