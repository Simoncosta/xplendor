<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Só o próprio ou um admin da MESMA empresa edita o utilizador; o alvo tem
     * de pertencer à empresa da rota. A password só pode ser mudada pelo próprio.
     */
    public function authorize(): bool
    {
        $auth   = $this->user();
        $target = User::find((int) $this->route('user'));

        if (! $auth || ! $target || (int) $target->company_id !== (int) $this->route('id')) {
            return false;
        }

        $isSelf = (int) $auth->id === (int) $target->id;

        if ($this->filled('password') && ! $isSelf) {
            return false;
        }

        return $isSelf
            || ($auth->role === 'admin' && (int) $auth->company_id === (int) $target->company_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'image', 'max:2048'],
            'birthdate' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],

            'password' => [
                'sometimes',
                'nullable',
                'confirmed',
                // Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
                Password::min(8),
            ],
            'password_confirmation' => ['nullable', 'string'],
        ];
    }
}
