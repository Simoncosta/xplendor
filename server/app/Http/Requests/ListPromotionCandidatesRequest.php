<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/companies/{companyId}/stock/promotion-candidates
 *
 * Filtros + ordenação + paginação. Tudo opcional — defaults definidos no
 * `StockPromotionRepository`. Authorize() devolve true porque o tenant
 * guard vive no controller (sec 11 do CLAUDE.md — confirmar no backend,
 * nunca confiar no frontend).
 */
class ListPromotionCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_type'      => ['nullable', Rule::in(['car', 'motorcycle', 'motorhome', 'caravan'])],
            'status'            => ['nullable', 'array'],
            'status.*'          => [Rule::in(['active', 'available_soon', 'reserved'])],
            'min_price'         => ['nullable', 'numeric', 'min:0'],
            'max_price'         => ['nullable', 'numeric', 'min:0'],
            'min_days_in_stock' => ['nullable', 'integer', 'min:0'],
            'max_days_in_stock' => ['nullable', 'integer', 'min:0'],
            'price_signal'      => ['nullable', 'array'],
            'price_signal.*'    => [Rule::in(['overpriced', 'slightly_high', 'fair', 'competitive'])],
            'only_marked'       => ['nullable', 'boolean'],
            'sort_by'           => ['nullable', Rule::in(['days_in_stock', 'price', 'views', 'leads', 'ips'])],
            'sort_dir'          => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Devolve o subset de filtros sem chaves nulas — o Repository assume
     * "ausente == sem filtro". Mapeamento 1:1 dos campos validados acima
     * para o shape consumido por `StockPromotionRepository::queryCandidates`.
     */
    public function filters(): array
    {
        return array_filter([
            'vehicle_type'      => $this->input('vehicle_type'),
            'status'            => $this->input('status'),
            'min_price'         => $this->input('min_price'),
            'max_price'         => $this->input('max_price'),
            'min_days_in_stock' => $this->input('min_days_in_stock'),
            'max_days_in_stock' => $this->input('max_days_in_stock'),
            'price_signal'      => $this->input('price_signal'),
            'only_marked'       => $this->boolean('only_marked') ?: null,
            'sort_by'           => $this->input('sort_by'),
            'sort_dir'          => $this->input('sort_dir'),
        ], static fn($v) => $v !== null && $v !== '');
    }
}
