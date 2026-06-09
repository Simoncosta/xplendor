<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Atualização parcial dos dados do comprador (PII) num car_sale existente —
 * ou criação quando ainda não existe.
 *
 * Diferenças face ao StoreCarSaleRequest:
 *   - NÃO valida o car inteiro (CarRequest). Só campos do sale.
 *   - sold_at NÃO é editável (efeitos colaterais em métricas/learning).
 *   - Todos os campos são nullable — permite UPDATE parcial.
 *   - Enums (sale_channel, buyer_gender, buyer_age_range) continuam validados
 *     mas como nullable.
 *
 * Tenant + auth: o controller verifica $user->company_id === $companyId
 * antes de chamar o service. Aqui authorize() devolve true por essa razão.
 */
class UpdateCarSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_price'      => ['nullable', 'numeric', 'min:0'],
            'sale_channel'    => ['nullable', Rule::in(['online', 'in_person', 'referral', 'trade_in'])],
            'buyer_name'      => ['nullable', 'string', 'max:255'],
            'buyer_phone'     => ['nullable', 'string', 'max:50'],
            'buyer_email'     => ['nullable', 'email', 'max:255'],
            'buyer_gender'    => ['nullable', Rule::in(['male', 'female', 'company'])],
            'buyer_age_range' => ['nullable', Rule::in(['18-30', '31-45', '46-60', '60+'])],
            'contact_consent' => ['nullable', 'boolean'],
            'notes'           => ['nullable', 'string'],
        ];
    }
}
