<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/companies/{companyId}/stock/promotion/{carId}
 *
 * Tenant + auth: o controller verifica $user->company_id === $companyId
 * (ou role root). Aqui authorize() devolve true por essa razão.
 *
 * `note` é a única coisa que o utilizador escreve livremente — limitado a
 * 500 chars (suficiente para "Promoção de Verão — guardada para Agosto"
 * e contexto similar; texto longo não é o caso de uso).
 */
class StorePromotionPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
