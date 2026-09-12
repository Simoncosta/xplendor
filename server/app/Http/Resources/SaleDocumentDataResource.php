<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS Fase 3 — shape dos dados dos documentos de venda (empresa + viatura +
 * cliente). Allow-list. PII — endpoint interno tenant-scoped; nunca público.
 */
class SaleDocumentDataResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var array $d */
        $d = $this->resource;
        $company  = $d['company'] ?? null;
        $car      = $d['car'] ?? null;
        $customer = $d['customer'] ?? null;
        $sale     = $d['sale'] ?? null;

        return [
            'company' => $company ? [
                'fiscal_name' => $company->fiscal_name,
                'trade_name'  => $company->trade_name,
                'logo_path'   => $company->logo_path, // logo no topo dos documentos (fallback textual)
                'nipc'        => $company->nipc,
                'address'     => $company->address,
                'postal_code' => $company->postal_code,
                'phone'       => $company->phone,
                'mobile'      => $company->mobile,
                'email'       => $company->email,
                'locality'    => $d['company_locality'] ?? null,
            ] : null,

            'car' => $car ? [
                'brand'              => $car->brand?->name,
                'model'              => $car->model?->name,
                'version'            => $car->version,
                'license_plate'      => $car->license_plate,
                'registration_month' => $car->registration_month,
                'registration_year'  => $car->registration_year,
            ] : null,

            'customer' => $customer ? [
                'name'                  => $customer->name,
                'nif'                   => $customer->nif,
                'phone'                 => $customer->phone,
                'email'                 => $customer->email,
                'address'               => $customer->address,
                'postal_code'           => $customer->postal_code,
                'locality'              => $customer->municipality?->name,
                'citizen_card_number'   => $customer->citizen_card_number,
                'citizen_card_validity' => optional($customer->citizen_card_validity)->toDateString(),
                'birth_date'            => optional($customer->birth_date)->toDateString(),
                'nationality'           => $customer->nationality,
                'profession'            => $customer->profession,
                'marital_status'        => $customer->marital_status,
            ] : null,

            'sale' => $sale ? [
                'sold_at' => optional($sale->sold_at)->toDateString(),
            ] : null,
        ];
    }
}
