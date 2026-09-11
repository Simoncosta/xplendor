<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS sub-fase 1c.2b — validação da Despesa.
 *
 * description/amount/date obrigatórios ao CRIAR; no UPDATE são `sometimes`
 * para permitir updates parciais (marcar pago, arquivar) sem reenviar tudo.
 * FKs todas opcionais (categoria/fornecedor/viatura).
 */
class ExpenseRequest extends FormRequest
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
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'description'         => [$req, 'string', 'max:255'],
            'amount'              => [$req, 'numeric', 'min:0'],
            'date'                => [$req, 'date'],

            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'supplier_id'         => ['nullable', 'integer', 'exists:suppliers,id'],
            'car_id'              => ['nullable', 'integer', 'exists:cars,id'],

            'is_paid'             => ['nullable', 'boolean'],
            'paid_at'             => ['nullable', 'date'],

            'archived'            => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
