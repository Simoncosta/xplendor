<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\CarRepository;
use App\Repositories\Contracts\CarBrandRepositoryInterface;
use App\Repositories\Contracts\CarmineConnectionRepositoryInterface;
use App\Repositories\Contracts\CarModelRepositoryInterface;
use App\Services\Api\ApiCarmineService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class CarmineConnectionService extends BaseService
{
    public function __construct(
        protected CarmineConnectionRepositoryInterface $carmineRepository,
        // Other repositories
        protected CarRepository $carRepository,
        protected CarBrandRepositoryInterface $carBrandRepository,
        protected CarModelRepositoryInterface $carModelRepository,
        protected CarExternalImageService $carExternalImageService,
        protected CarSaleService $carSaleService,
    ) {
        parent::__construct($carmineRepository);
    }

    public function getListaDetalhesViatura(int $companyId)
    {
        $this->syncCompanyCars($companyId);

        return $this->carRepository->getAll(
            ['*'],
            [],
            null,
            ['company_id' => $companyId],
        );
    }

    public function syncCompanyCars(int $companyId): array
    {
        $connection = $this->carmineRepository->findOrFail($companyId, 'company_id', ['*'], []);
        if (!isset($connection->id)) {
            throw new Exception('Não há dados com estes parâmetros.');
        }

        $api = new ApiCarmineService(
            dealerId: $connection->dealer_id,
            token: $connection->token
        );

        $response = $api->getListaDetalhesViatura();
        $totalFetched = count($response);

        $errors = [];
        $imported = 0;
        $updated = 0;

        collect($response)->each(function ($item) use ($companyId, &$errors, &$imported, &$updated) {
            try {
                $carmineMapData = $this->mapCarmineToXplendor($item, $companyId);
                $externalImages = $this->mapCarmineExternalImages($item);

                $carmine = $this->carRepository->findOrFail($carmineMapData['carmine_id'], 'carmine_id');

                if (isset($carmine['message'])) {
                    $car = $this->carRepository->store($carmineMapData);
                    $imported++;
                } else {
                    $car = $this->carRepository->update($carmine['id'], $carmineMapData);
                    $updated++;
                }

                if (isset($car->id)) {
                    $this->carExternalImageService->syncForCar($car->id, $companyId, 'carmine', $externalImages);

                    if (($carmineMapData['status'] ?? null) === 'sold') {
                        $this->carSaleService->markAsSold($car, [
                            'sold_at' => $carmineMapData['sold_at'] ?? now(),
                            'sale_price' => $carmineMapData['price_gross'] ?? null,
                            'skip_notification' => true,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'carmine_id' => $item['CodViatura'] ?? 'unknown',
                    'error'      => $e->getMessage(),
                ];

                Log::warning('[Carmine Import] Falha ao importar viatura', [
                    'carmine_id' => $item['CodViatura'] ?? 'unknown',
                    'error'      => $e->getMessage(),
                ]);
            }
        });

        Log::info('[Carmine Import] Concluído', [
            'company_id' => $companyId,
            'total_recebidos' => $totalFetched,
            'importados' => $imported,
            'atualizados' => $updated,
            'erros'      => count($errors),
        ]);

        if (!empty($errors)) {
            Log::warning('[Carmine Import] Viaturas com erro', $errors);
        }

        return [
            'company_id' => $companyId,
            'total_received' => $totalFetched,
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    public function mapCarmineToXplendor(array $data, int $companyId)
    {
        // Marca — normaliza nome antes de procurar
        $brandName = $data['Marca']['Nome'] === 'Mercedes Benz' ? 'Mercedes-Benz' : $data['Marca']['Nome'];
        $brand = $this->carBrandRepository->findOrFail($brandName, 'name');
        if (!isset($brand->id)) {
            throw new \Exception("Marca não encontrada: {$brandName}");
        }

        // Modelo
        $modelName = $data['Modelo']['Nome'];
        $model = $this->carModelRepository->findOrFail($modelName, 'name');
        if (!isset($model->id)) {
            throw new \Exception("Modelo não encontrado: {$modelName}");
        }

        // Combustível
        $fuelMap = [
            'Gasolina'  => 'gasoline',
            'Electrico' => 'electric',
            'Gasóleo'   => 'diesel',
            'Híbrido'   => 'hybrid',
        ];
        $fuelType = $fuelMap[$data['Combustivel']['Nome']] ?? null;

        // Condição
        $conditionMap = [
            'Como novo' => 'like_new',
            'Usado'     => 'used',
            'Novo'      => 'new',
            'Bom'       => 'good',
        ];
        $condition = $conditionMap[$data['Estado']['Nome']] ?? null;

        // Segmento
        $segmentMap = [
            'Coupé'    => 'coupe',
            'Sedan'    => 'sedan',
            'SUV / TT' => 'suv_tt',
            'SUV / TT' => 'suv_tt',
            'Citadino' => 'city_car',
        ];
        $segment = $segmentMap[$data['Tipo']['Nome']] ?? 'sedan';

        // Cor exterior
        $colorMap = [
            'Verde Escuro'      => 'dark-green',
            'Preto'             => 'black',
            'Branco'            => 'white',
            'Cinza'             => 'gray',
            'Cinza Antracite'   => 'gray-antracite',
            'Azul'              => 'blue',
            'Azul Escuro'       => 'dark-blue',
            'Vermelho'          => 'red',
            'Branco'            => 'white',
        ];
        $exteriorColor = $colorMap[$data['Cor']['Nome']] ?? null;

        return [
            "carmine_id"              => $data['CodViatura'],
            "status"                  => $data['DisponivelBrevemente']
                ? 'available_soon'
                : ($data['Vendido'] ? 'sold' : 'active'),
            "sold_at"                 => $data['Vendido']
                ? ($this->parseCarmineDate($data['UltimaAlteracao'] ?? null) ?? now())
                : null,
            "is_resume"               => $data['Retoma'] ?? false,
            "origin"                  => $data['Importado'] ? 'imported' : 'national',
            "license_plate"           => $data['Matricula'],
            "vin"                     => $data['VIN'],
            "car_brand_id"            => $brand->id,
            "car_model_id"            => $model->id,
            "registration_month"      => $data['Mes'],
            "registration_year"       => $data['Ano'],
            "version"                 => $data['Motorizacao'],
            "public_version_name"     => $data['VersaoAlternatica'],
            "fuel_type"               => $fuelType,
            "power_hp"                => $data['Potencia'],
            "engine_capacity_cc"      => $data['Cilindrada'],
            "doors"                   => $data['Porta']['Nome'],
            "transmission"            => $data['Transmissao']['Nome'] === 'Automática ' || $data['Transmissao']['Nome'] === 'Automática' ? 'automatic' : 'manual',
            "segment"                 => $segment,
            "seats"                   => (int) preg_replace('/\D/', '', $data['Lugares']['Nome'] ?? ''),
            "exterior_color"          => $exteriorColor,
            "is_metallic"             => $data['Metalizado'],
            "interior_color"          => null,
            "condition"               => $condition,
            "mileage_km"              => $data['Km'] === "" ? 0 : $data['Km'],
            "price_gross"             => $this->normalizeMoney($data['Preco'] ?? null),
            "promo_price_gross"       => $this->normalizeMoney($data['PrecoPromo'] ?? null, true),
            "description_website_pt"  => $data['TextoGenericoAnuncios'],
            "youtube_url"             => $data['UrlVideo'],
            "company_id"              => $companyId,
            "car_created_at"          => $this->parseCarmineDate($data['DataCriacao'] ?? null),
            "updated_at"              => $this->parseCarmineDate($data['UltimaAlteracao'] ?? null),
        ];
    }

    private function parseCarmineDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        try {
            return Carbon::createFromFormat('d/m/Y H:i:s', $value);
        } catch (\Throwable $exception) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $exception) {
                Log::warning('[Carmine Import] Data inválida recebida do Carmine', [
                    'value' => $value,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }
        }
    }

    private function normalizeMoney(mixed $value, bool $nullableIfEmpty = false): ?float
    {
        if ($value === null || $value === '') {
            return $nullableIfEmpty ? null : 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = preg_replace('/[^\d,.-]/', '', (string) $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || !is_numeric($normalized)) {
            return $nullableIfEmpty ? null : 0.0;
        }

        return round((float) $normalized, 2);
    }

    private function mapCarmineExternalImages(array $data): array
    {
        return collect($data['Ficheiros'] ?? [])
            ->map(fn($file) => [
                'external_url' => $file['Ficheiro'] ?? null,
                'external_index' => $file['Indice'] ?? null,
                'is_primary' => (bool) ($file['Principal'] ?? false),
                'sort_order' => $file['Ordenador'] ?? null,
            ])
            ->all();
    }
}
