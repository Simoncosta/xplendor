<?php

namespace App\Services;

use App\Models\CarSale;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Repositories\Contracts\CarSaleRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CarSaleService extends BaseService
{
    public function __construct(
        protected CarSaleRepositoryInterface $carSaleRepository,
        protected CarRepositoryInterface $carRepository,
        protected CarService $carService,
    ) {
        parent::__construct($carSaleRepository);
    }

    public function closeSale(int $companyId, int $carId, array $data): CarSale
    {
        $car = $this->carRepository->findOrFail(
            $carId,
            'id',
            ['*'],
            [],
            ['company_id' => $companyId]
        );

        if (!isset($car->id)) {
            throw new \RuntimeException('Viatura não encontrada para esta empresa.');
        }

        return DB::transaction(function () use ($companyId, $carId, $data) {
            $carData = $this->extractCarData($data, $companyId);
            $saleData = $this->extractSaleData($data, $companyId, $carId);

            $this->carService->update($carId, $carData);

            $existingSale = $this->carSaleRepository->findOrFail($carId, 'car_id');

            if (isset($existingSale->id)) {
                $sale = $this->carSaleRepository->update($existingSale->id, $saleData);
            } else {
                $sale = $this->carSaleRepository->store($saleData);
            }

            Log::info('[Car Sale] Venda registada com sucesso', [
                'company_id' => $companyId,
                'car_id' => $carId,
                'sale_price' => $saleData['sale_price'],
                'sale_channel' => $saleData['sale_channel'],
            ]);

            return $sale->load(['car', 'company']);
        });
    }

    private function extractCarData(array $data, int $companyId): array
    {
        return [
            'status' => 'sold',
            'origin' => $data['origin'],
            'license_plate' => $data['license_plate'] ?? null,
            'vin' => $data['vin'] ?? null,
            'registration_month' => $data['registration_month'] ?? null,
            'registration_year' => $data['registration_year'],
            'car_brand_id' => $data['car_brand_id'],
            'car_model_id' => $data['car_model_id'],
            'version' => $data['version'],
            'public_version_name' => $data['public_version_name'] ?? null,
            'fuel_type' => $data['fuel_type'],
            'power_hp' => $data['power_hp'],
            'engine_capacity_cc' => $data['engine_capacity_cc'],
            'doors' => $data['doors'],
            'transmission' => $data['transmission'],
            'segment' => $data['segment'],
            'seats' => $data['seats'],
            'exterior_color' => $data['exterior_color'],
            'is_metallic' => $data['is_metallic'] ?? false,
            'interior_color' => $data['interior_color'] ?? null,
            'condition' => $data['condition'],
            'mileage_km' => $data['mileage_km'] ?? null,
            'co2_emissions' => $data['co2_emissions'] ?? null,
            'toll_class' => $data['toll_class'] ?? null,
            'cylinders' => $data['cylinders'] ?? null,
            'warranty_available' => $data['warranty_available'] ?? null,
            'warranty_due_date' => $data['warranty_due_date'] ?? null,
            'warranty_km' => $data['warranty_km'] ?? null,
            'service_records' => $data['service_records'] ?? null,
            'has_spare_key' => $data['has_spare_key'] ?? false,
            'has_manuals' => $data['has_manuals'] ?? false,
            'price_gross' => $data['price_gross'] ?? null,
            'promo_price_gross' => $data['promo_price_gross'] ?? null,
            'price_net' => $data['price_net'] ?? null,
            'hide_price_online' => $data['hide_price_online'] ?? false,
            'monthly_payment' => $data['monthly_payment'] ?? null,
            'extras' => $data['extras'] ?? [],
            'lifestyle' => $data['lifestyle'] ?? null,
            'description_website_pt' => $data['description_website_pt'] ?? null,
            'description_website_en' => $data['description_website_en'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
            'images' => $data['images'] ?? [],
            'existing_images' => $data['existing_images'] ?? [],
            'images_meta' => $data['images_meta'] ?? [],
            'existing_images_meta' => $data['existing_images_meta'] ?? [],
            'exterior_360_images' => $data['exterior_360_images'] ?? [],
            'exterior_360_meta' => $data['exterior_360_meta'] ?? [],
            'company_id' => $companyId,
        ];
    }

    private function extractSaleData(array $data, int $companyId, int $carId): array
    {
        return [
            'car_id' => $carId,
            'company_id' => $companyId,
            'sale_price' => $data['sale_price'] ?? null,
            'buyer_gender' => $data['buyer_gender'],
            'buyer_age_range' => $data['buyer_age_range'],
            'sale_channel' => $data['sale_channel'],
            'buyer_name' => $data['contact_consent'] ? ($data['buyer_name'] ?? null) : null,
            'buyer_phone' => $data['contact_consent'] ? ($data['buyer_phone'] ?? null) : null,
            'buyer_email' => $data['contact_consent'] ? ($data['buyer_email'] ?? null) : null,
            'contact_consent' => (bool) ($data['contact_consent'] ?? false),
            'notes' => $data['notes'] ?? null,
            'sold_at' => now(),
        ];
    }
}
