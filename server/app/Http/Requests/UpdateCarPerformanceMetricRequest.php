<?php

namespace App\Http\Requests;

use App\Models\CarPerformanceMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarPerformanceMetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Apenas campos manuais são editáveis após criação
            'spend_amount'   => ['sometimes', 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'sale_price'     => ['sometimes', 'numeric', 'min:0'],
            'cost_per_sale'  => ['sometimes', 'numeric', 'min:0'],
            'impressions'    => ['sometimes', 'integer', 'min:0'],
            'clicks'         => ['sometimes', 'integer', 'min:0'],
            'sessions'       => ['sometimes', 'integer', 'min:0'],
            'leads_count'    => ['sometimes', 'integer', 'min:0'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'data_source'    => ['sometimes', Rule::in(CarPerformanceMetric::DATA_SOURCES)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $impressions = (int) $this->input('impressions', 0);
            $clicks      = (int) $this->input('clicks', 0);

            if ($impressions === 0 && $clicks > 0) {
                $v->errors()->add('clicks', 'Não é possível ter cliques sem impressões.');
            }
        });
    }
}
