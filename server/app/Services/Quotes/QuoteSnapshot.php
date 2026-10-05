<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quote;

/**
 * O que o cliente vê num orçamento, em dados (sem formatação). É o que fica
 * congelado em cada versão enviada e é a única entrada do PDF, por isso uma versão
 * antiga volta a dar exatamente o mesmo documento.
 */
class QuoteSnapshot
{
    public static function fromQuote(Quote $quote): array
    {
        $quote->loadMissing(['lines', 'customer']);
        $lines = $quote->lines->map(fn ($l) => [
            'name'           => $l->name,
            'description'    => $l->description,
            'unit'           => $l->unit,
            'billing_type'   => $l->billing_type,
            'quantity'       => (float) $l->quantity,
            'unit_price'     => (float) $l->unit_price,
            'discount_type'  => $l->discount_type,
            'discount_value' => $l->discount_value !== null ? (float) $l->discount_value : null,
        ])->values()->all();

        $calc = QuoteCalculator::compute($lines, [
            'type' => $quote->global_discount_type, 'value' => $quote->global_discount_value, 'target' => $quote->global_discount_target,
        ]);

        return [
            'number'      => $quote->number,
            'version'     => (int) $quote->version,
            'status'      => $quote->status,
            'issued_at'   => $quote->sent_at?->setTimezone(Quote::TIMEZONE)->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'customer'    => [
                'name'  => $quote->client_name,
                'email' => $quote->client_email,
                'phone' => $quote->client_phone,
                'nif'   => $quote->customer?->nif,
                'contact' => $quote->client_email || $quote->client_phone ? null : $quote->client_contact,
            ],
            'title'  => $quote->title,
            'intro'  => $quote->intro,
            'lines'  => $calc['lines'],
            'buckets' => $calc['buckets'],
            'global_discount' => [
                'type'   => $quote->global_discount_type,
                'value'  => $quote->global_discount_value !== null ? (float) $quote->global_discount_value : null,
                'target' => $quote->global_discount_target,
                'label'  => $quote->global_discount_label ?: config('quotes.defaults.global_discount_label'),
            ],
            'minimum_contract_months' => $quote->minimum_contract_months,
            'monthly_start_terms'     => $quote->monthly_start_terms,
            'payment_terms_monthly'   => $quote->payment_terms_monthly,
            'payment_terms_one_off'   => $quote->payment_terms_one_off,
        ];
    }
}
