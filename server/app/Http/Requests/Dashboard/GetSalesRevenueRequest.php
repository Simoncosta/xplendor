<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Visão 3 do Dashboard (2026-06-25) — query params do
 * `GET /companies/{id}/dashboard/sales-revenue`.
 *
 * `from`/`to` em formato `Y-m-d`. `granularity` enum fechado.
 * Default: range vazio é rejeitado — o frontend SEMPRE envia (mesmo no
 * preset "Este mês" calcula no cliente). Mantém o backend simples.
 */
class GetSalesRevenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from'        => ['required', 'date_format:Y-m-d'],
            'to'          => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['nullable', Rule::in(['month', 'year'])],
        ];
    }
}
