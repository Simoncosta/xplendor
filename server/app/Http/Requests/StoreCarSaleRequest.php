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
            'buyer_gender' => ['required', Rule::in(['male', 'female', 'company'])],
            'buyer_age_range' => ['required', Rule::in(['18-30', '31-45', '46-60', '60+'])],
            'sale_channel' => ['required', Rule::in(['online', 'in_person', 'referral', 'trade_in'])],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'contact_consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
