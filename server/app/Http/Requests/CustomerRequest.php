<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS — validação do Cliente. Só o `name` é obrigatório (ao criar); no update é
 * `sometimes` (permite updates parciais como arquivar). Restante nullable.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'name'                  => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'nif'                   => ['nullable', 'string', 'max:50'],
            'phone'                 => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:255'],

            // Morada — mesmo padrão de companies/suppliers.
            'address'               => ['nullable', 'string', 'max:255'],
            'postal_code'           => ['nullable', 'string', 'max:20'],
            'district_id'           => ['nullable', 'integer', 'exists:districts,id'],
            'municipality_id'       => ['nullable', 'integer', 'exists:municipalities,id'],
            'parish_id'             => ['nullable', 'integer', 'exists:parishes,id'],

            // Dados legais (documentos).
            'citizen_card_number'   => ['nullable', 'string', 'max:50'],
            'citizen_card_validity' => ['nullable', 'date'],
            'birth_date'            => ['nullable', 'date'],
            'nationality'           => ['nullable', 'string', 'max:100'],
            'profession'            => ['nullable', 'string', 'max:255'],
            'marital_status'        => ['nullable', 'string', 'max:50'],

            'contact_consent'       => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
            'archived'              => ['nullable', 'boolean'],
        ];
    }
}
