<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS sub-fase 1c.1 — validação do Fornecedor.
 * Só o `name` é obrigatório; todo o resto é opcional (permite criação rápida).
 */
class SupplierRequest extends FormRequest
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
        // `name` obrigatório ao criar; `sometimes` no update — permite updates
        // parciais (arquivar/restaurar) sem reenviar o nome (1c.2b).
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'name'            => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'nif'             => ['nullable', 'string', 'max:50'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],

            // Morada — mesmo padrão da empresa (campos inline + FKs lookup).
            'address'         => ['nullable', 'string', 'max:255'],
            'postal_code'     => ['nullable', 'string', 'max:20'],
            'district_id'     => ['nullable', 'integer', 'exists:districts,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'parish_id'       => ['nullable', 'integer', 'exists:parishes,id'],

            'iban'            => ['nullable', 'string', 'max:34'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'archived'        => ['nullable', 'boolean'], // 1c.2b — arquivo
        ];
    }
}
