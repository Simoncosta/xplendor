<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $carRules = (new CarRequest())->rules();
        $carRules['status'] = ['required', Rule::in(['sold'])];

        return array_merge($carRules, [
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sold_at' => ['nullable', 'date'],
            // DMS — cliente da venda (scoped à empresa da rota).
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', (int) $this->route('id'))],
            'buyer_age' => ['nullable', 'integer', 'min:18', 'max:120'],
            'buyer_gender' => ['required', Rule::in(['male', 'female', 'company'])],
            'buyer_age_range' => ['required', Rule::in(['18-30', '31-45', '46-60', '60+'])],
            'sale_channel' => ['required', Rule::in(['online', 'in_person', 'referral', 'trade_in'])],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'contact_consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],

            // Fase 1 — registo de venda enriquecido (tudo opcional).
            'advertised_price'  => ['nullable', 'numeric', 'min:0'],
            'discount_amount'   => ['nullable', 'numeric', 'min:0'],
            'offers'            => ['nullable', 'string'],
            'has_financing'     => ['nullable', 'boolean'],
            'financing_entity'  => ['nullable', 'string', 'max:255'],
            'financed_amount'   => ['nullable', 'numeric', 'min:0'],
            'has_trade_in'      => ['nullable', 'boolean'],
            'trade_in_vehicle'  => ['nullable', 'string', 'max:255'],
            'trade_in_value'    => ['nullable', 'numeric', 'min:0'],
            'first_motorhome'   => ['nullable', 'boolean'],
            'previous_vehicle'  => ['nullable', 'string', 'max:255'],
        ]);
    }
}
