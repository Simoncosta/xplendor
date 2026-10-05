<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\QuoteLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulário do orçamento de serviços (criar e alterar). O cliente é um dos
 * Clientes da XPLENDOR (customer_id) ou um novo (new_customer). Os totais são
 * calculados no servidor (QuoteCalculator), nunca vêm do ecrã.
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // o grupo /admin e o controller garantem root
    }

    public function rules(): array
    {
        return [
            'customer_id'            => ['nullable', 'integer'],
            'new_customer'           => ['nullable', 'array'],
            'new_customer.name'      => ['required_with:new_customer', 'string', 'max:255'],
            'new_customer.phone'     => ['nullable', 'string', 'max:50'],
            'new_customer.email'     => ['nullable', 'email', 'max:255'],
            'company_id'             => ['nullable', 'integer', 'exists:companies,id'],
            'title'                  => ['nullable', 'string', 'max:255'],
            'intro'                  => ['nullable', 'string', 'max:5000'],
            'notes'                  => ['nullable', 'string', 'max:5000'],
            'minimum_contract_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'monthly_start_terms'    => ['nullable', 'string', 'max:2000'],
            'payment_terms_monthly'  => ['nullable', 'string', 'max:2000'],
            'payment_terms_one_off'  => ['nullable', 'string', 'max:2000'],
            'global_discount_type'   => ['nullable', Rule::in(['percent', 'amount'])],
            'global_discount_value'  => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'global_discount_target' => ['nullable', Rule::in(QuoteLine::BILLING_TYPES)],
            'global_discount_label'  => ['nullable', 'string', 'max:120'],
            'lines'                  => ['sometimes', 'array', 'max:100'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', 'exists:service_catalog_items,id'],
            'lines.*.name'           => ['required', 'string', 'max:255'],
            'lines.*.description'    => ['nullable', 'string', 'max:2000'],
            'lines.*.unit'           => ['required', Rule::in(QuoteLine::UNITS)],
            'lines.*.billing_type'   => ['required', Rule::in(QuoteLine::BILLING_TYPES)],
            'lines.*.quantity'       => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit_price'     => ['required', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.discount_type'  => ['nullable', Rule::in(['percent', 'amount'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.is_optional'    => ['sometimes', 'boolean'],
            'lines.*.in_package'     => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['new_customer.name' => 'nome do cliente', 'lines.*.name' => 'nome da linha', 'lines.*.quantity' => 'quantidade', 'lines.*.unit_price' => 'preço'];
    }
}
