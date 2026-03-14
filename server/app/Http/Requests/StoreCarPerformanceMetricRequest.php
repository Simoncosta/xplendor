<?php

namespace App\Http\Requests;

use App\Models\CarPerformanceMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarPerformanceMetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controlo feito no controller via policy/scope
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(CarPerformanceMetric::CHANNELS)],

            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date', 'after_or_equal:period_start'],

            'impressions'   => ['sometimes', 'integer', 'min:0'],
            'clicks'        => ['sometimes', 'integer', 'min:0'],
            'sessions'      => ['sometimes', 'integer', 'min:0'],

            'spend_amount'  => ['sometimes', 'numeric', 'min:0'],
            'cost_per_sale' => ['sometimes', 'numeric', 'min:0'],

            'leads_count'    => ['sometimes', 'integer', 'min:0'],

            'sale_price'     => ['sometimes', 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],

            'data_source' => ['sometimes', Rule::in(CarPerformanceMetric::DATA_SOURCES)],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Validação de negócio: clicks não pode ser maior que impressions.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $impressions = (int) $this->input('impressions', 0);
            $clicks      = (int) $this->input('clicks', 0);

            if ($impressions === 0 && $clicks > 0) {
                $v->errors()->add(
                    'clicks',
                    'Não é possível ter cliques sem impressões. Dado incoerente.'
                );
            }

            if ($clicks > $impressions && $impressions > 0) {
                $v->errors()->add(
                    'clicks',
                    'O número de cliques não pode ser superior ao número de impressões.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'period_end.after_or_equal' => 'A data de fim deve ser igual ou posterior à data de início.',
            'channel.in'                => 'Canal inválido. Valores aceites: ' . implode(', ', CarPerformanceMetric::CHANNELS),
            'data_source.in'            => 'Fonte de dados inválida.',
        ];
    }
}
