<?php

namespace App\Services;

use App\Repositories\Contracts\CarmineConnectionRepositoryInterface;
use App\Services\Api\ApiCarmineService;
use Exception;

class CarmineConnectionService extends BaseService
{
    public function __construct(protected CarmineConnectionRepositoryInterface $carmineRepository)
    {
        parent::__construct($carmineRepository);
    }

    public function getListaDetalhesViatura(int $companyId)
    {
        $connection = $this->carmineRepository->findOrFail($companyId, 'company_id', ['*'], []);
        if (!isset($connection->id)) {
            throw new Exception('Não há dados com estes parâmetros.');
        }

        // Criar API dinamicamente
        $api = new ApiCarmineService(
            dealerId: $connection->dealer_id,
            token: $connection->token
        );

        $response = $api->getListaDetalhesViatura();

        dd($this->mapCarmineToXplendor($response));

        // Executar chamada
        return;
    }

    public function mapCarmineToXplendor(array $data)
    {
        return collect($data)->map(function ($item) {
            return [
                "carmine_id" => $item['CodViatura'],
                "status" => $item['Reservado'] ? 'available_soon' : ($item['Vendido'] ? 'sold' : 'active'),
                "origin" => $item['Importado'] ? "imported" : "national",
                "license_plate" => $item["Matricula"],
                "vin" => $item["VIN"],
                "car_brand_id" => "",
                "car_model_id" => "",
                "registration_month" => "",
                "registration_year" => "",
                "version" => "",
                "public_version_name" => "",
                "fuel_type" => "",
                "power_hp" => "",
                "engine_capacity_cc" => "",
                "doors" => "",
                "transmission" => "",
                "segment" => "",
                "seats" => "",
                "exterior_color" => "",
                "is_metallic" => "",
                "interior_color" => "",
                "condition" => "enum('new', 'used', 'like_new', 'good', 'service',...",
                "mileage_km" => "",
                "co2_emissions" => "",
                "toll_class" => "",
                "cylinders" => "",
                "warranty_available" => "",
                "warranty_due_date" => "",
                "warranty_km" => "",
                "service_records" => "",
                "has_spare_key" => "",
                "has_manuals" => "",
                "price_gross" => "",
                "price_net" => "",
                "hide_price_online" => "",
                "monthly_payment" => "",
                "extras" => "",
                "lifestyle" => "",
                "description_website_pt" => "",
                "youtube_url" => "",
            ];
        });
    }
}
