<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\SatisfactionReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DMS — payload PÚBLICO do relatório de satisfação (read-only, Incremento 1).
 *
 * Devolve o MÍNIMO: branding da empresa (nome + logo), as fotos da viatura, e
 * atributos NÃO sensíveis do carro (km, garantia). Da venda, expõe APENAS a
 * data de início da garantia (= sold_at) — uma data, não identifica ninguém.
 * NUNCA PII do comprador (nome/email/telefone) nem preço da venda. Link público.
 */
class SatisfactionReportPublicResource extends JsonResource
{
    public static $wrap = null;

    /** @param SatisfactionReport $this->resource */
    public function toArray(Request $request): array
    {
        /** @var SatisfactionReport $report */
        $report  = $this->resource;
        $company = $report->company;
        $car     = $report->car;

        $images = $car
            ? collect($car->images)
                ->map(fn ($img) => ['url' => $img->image])
                ->filter(fn ($i) => ! empty($i['url']))
                ->values()
            : collect();

        return [
            'status' => $report->status,
            'rating' => $report->rating,
            // Mensagem copiável para o Google (só existe no ramo ≥4). No ramo <4
            // o comentário é interno e NÃO é exposto aqui.
            'review_message' => $report->public_message,
            'company' => [
                'name'              => $company?->fiscal_name,
                'logo_path'         => $company?->logo_path,
                'google_review_url' => $company?->google_review_url,
                // Redes sociais do STAND (links públicos, não PII).
                'website'           => $company?->website,
                'instagram'         => $company?->instagram,
                'facebook'          => $company?->facebook,
                'youtube'           => $company?->youtube,
            ],
            'car' => [
                'brand'             => $car?->brand?->name,
                'model'             => $car?->model?->name,
                'version'           => $car?->public_version_name ?: $car?->version,
                'registration_year' => $car?->registration_year,
                'mileage_km'        => $car?->mileage_km,
                'images'            => $images,
            ],
            // Mostrador de garantia — total (atributo do carro) + data de início
            // (= sold_at). ÚNICO dado da venda exposto: uma data.
            'warranty' => [
                'total_months' => $car?->warranty_months,
                'start_date'   => optional($report->carSale?->sold_at)->toDateString(),
            ],
            // Fotos carregadas pelo cliente (o widget "As suas fotos").
            'photos' => collect($report->photos)
                ->map(fn ($p) => ['id' => $p->id, 'url' => $p->path])
                ->values(),
        ];
    }
}
