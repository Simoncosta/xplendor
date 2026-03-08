<?php

namespace App\Services;

use App\Repositories\CarRepository;
use App\Repositories\Contracts\CarBrandRepositoryInterface;
use App\Repositories\Contracts\CarmineConnectionRepositoryInterface;
use App\Repositories\Contracts\CarModelRepositoryInterface;
use App\Services\Api\ApiCarmineService;
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
    ) {
        parent::__construct($carmineRepository);
    }

    public function getListaDetalhesViatura(int $companyId)
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

        $errors = [];
        $imported = 0;
        $updated = 0;

        collect($response)->each(function ($item) use ($companyId, &$errors, &$imported, &$updated) {
            try {
                $carmineMapData = $this->mapCarmineToXplendor($item, $companyId);

                $carmine = $this->carRepository->findOrFail($carmineMapData['carmine_id'], 'carmine_id');

                if (isset($carmine['message'])) {
                    $this->carRepository->store($carmineMapData);
                    $imported++;
                } else {
                    $this->carRepository->update($carmine['id'], $carmineMapData);
                    $updated++;
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
            'importados' => $imported,
            'atualizados' => $updated,
            'erros'      => count($errors),
        ]);

        if (!empty($errors)) {
            Log::warning('[Carmine Import] Viaturas com erro', $errors);
        }

        return $this->carRepository->getAll(
            ['*'],
            [],
            null,
            ['company_id' => $companyId],
        );
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
            "transmission"            => $data['Transmissao']['Nome'] === 'Automática' ? 'automatic' : 'manual',
            "segment"                 => $segment,
            "seats"                   => (int) preg_replace('/\D/', '', $data['Lugares']['Nome'] ?? ''),
            "exterior_color"          => $exteriorColor,
            "is_metallic"             => $data['Metalizado'],
            "interior_color"          => null,
            "condition"               => $condition,
            "mileage_km"              => $data['Km'],
            "price_gross"             => $data['Preco'] === '' ? 0 : $data['Preco'],
            "description_website_pt"  => $data['TextoGenericoAnuncios'],
            "youtube_url"             => $data['UrlVideo'],
            "company_id"              => $companyId,
            "created_at"              => $data['DataCriacao'],
            "updated_at"              => $data['UltimaAlteracao'],
        ];
    }
}
