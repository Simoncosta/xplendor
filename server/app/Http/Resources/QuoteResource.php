<?php

namespace App\Http\Resources;

use App\Services\Quotes\QuoteCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * XPLENDOR — shape do orçamento de serviços (allow-list). Totais mensal e de valor
 * único sempre separados, sem IVA. As notas internas só vão para o lado /admin.
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');

        return [
            'id'             => $this->id,
            'number'         => $this->number,
            'display_number' => $this->displayNumber(),
            'version'        => (int) $this->version,
            'status'         => $this->status,
            'legacy_status'  => $this->when($admin, $this->legacy_status),
            'company_id'     => $this->company_id,
            'company_name'   => $this->whenLoaded('company', fn () => $this->company?->trade_name ?: $this->company?->fiscal_name),
            'is_linked'      => $this->company_id !== null,
            'customer_id'    => $this->customer_id,
            'client_name'    => $this->client_name,
            'client_email'   => $this->client_email,
            'client_phone'   => $this->client_phone,
            'client_contact' => $this->client_contact,
            'title'          => $this->title,
            'intro'          => $this->intro,
            'description'    => $this->description,
            'total_monthly'  => (float) $this->total_monthly,
            'total_one_off'  => (float) $this->total_one_off,
            'global_discount_type'   => $this->global_discount_type,
            'global_discount_value'  => $this->global_discount_value !== null ? (float) $this->global_discount_value : null,
            'global_discount_target' => $this->global_discount_target,
            'global_discount_label'  => $this->global_discount_label,
            'minimum_contract_months' => $this->minimum_contract_months,
            'monthly_start_terms'   => $this->monthly_start_terms,
            'payment_terms_monthly' => $this->payment_terms_monthly,
            'payment_terms_one_off' => $this->payment_terms_one_off,
            'sent_at'        => optional($this->sent_at)->toIso8601String(),
            'valid_until'    => optional($this->valid_until)->toDateString(),
            'decided_at'     => optional($this->decided_at)->toIso8601String(),
            'expired_at'     => optional($this->expired_at)->toIso8601String(),
            'notes'          => $this->when($admin, $this->notes),
            'lines'          => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id'              => $l->id,
                'catalog_item_id' => $l->catalog_item_id,
                'name'            => $l->name,
                'description'     => $l->description,
                'unit'            => $l->unit,
                'billing_type'    => $l->billing_type,
                'quantity'        => (float) $l->quantity,
                'unit_price'      => (float) $l->unit_price,
                'discount_type'   => $l->discount_type,
                'discount_value'  => $l->discount_value !== null ? (float) $l->discount_value : null,
                'line_total'      => (float) $l->line_total,
            ])->values()->all()),
            // Subtotal, desconto de pacote e total de cada um (mensal e valor único).
            'buckets'        => $this->whenLoaded('lines', fn () => QuoteCalculator::compute(
                $this->lines->map(fn ($l) => ['quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'billing_type' => $l->billing_type,
                    'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value])->all(),
                ['type' => $this->global_discount_type, 'value' => $this->global_discount_value, 'target' => $this->global_discount_target]
            )['buckets']),
            'versions'       => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($v) => [
                'version'     => (int) $v->version,
                'number'      => $v->number,
                'sent_at'     => optional($v->sent_at)->toIso8601String(),
                'valid_until' => optional($v->valid_until)->toDateString(),
            ])->values()->all()),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
