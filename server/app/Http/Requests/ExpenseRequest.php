<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DMS sub-fase 1c.2b — validação da Despesa.
 *
 * description/amount/date obrigatórios ao CRIAR; no UPDATE são `sometimes`
 * para permitir updates parciais (marcar pago, arquivar) sem reenviar tudo.
 * FKs todas opcionais (categoria/fornecedor/viatura), e só da empresa do endereço (F0 do
 * isolamento): um ID de outra empresa dá 422. O ExpenseService volta a verificar.
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
        $companyId = (int) $this->route('id');

        return [
            'description'         => [$req, 'string', 'max:255'],
            'amount'              => [$req, 'numeric', 'min:0'],
            'date'                => [$req, 'date'],

            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('company_id', $companyId), function ($attribute, $value, $fail) {
                // Reservada às cobranças da XPLENDOR (ninguém a escolhe numa despesa à mão).
                if ($value && \App\Models\ExpenseCategory::whereKey($value)->where('system_key', \App\Models\ExpenseCategory::SYSTEM_XPLENDOR)->exists()) {
                    $fail('A categoria XPLENDOR é reservada às cobranças da XPLENDOR.');
                }
            }],
            'supplier_id'         => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'car_id'              => ['nullable', 'integer', Rule::exists('cars', 'id')->where('company_id', $companyId)],

            'is_paid'             => ['nullable', 'boolean'],
            'paid_at'             => ['nullable', 'date'],

            'archived'            => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
