<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class CarService extends BaseService
{
    public function __construct(
        protected CarRepositoryInterface $carRepository,
        // Service
        protected CarImageService $carImageService,
        protected Car360ExteriorImageService $car360ExteriorImageService,
        protected CarAiAnalysesService $carAiAnalysesService,
    ) {
        parent::__construct($carRepository);
    }

    public function getAll(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed
    {
        return $this->carRepository->getAllWithAnalytics($columns, $relations, $perPage, $filters, $orderBy);
    }

    public function store(array $data): mixed
    {
        $car = $this->repository->store($data); // Cria carro e retorna ID
        $slug = Str::slug("{$data['car_brand_id']}-{$data['car_model_id']}");

        // Salvar imagens normais
        if (!empty($data['images'])) {
            $images = $data['images'];
            $meta = $data['images_meta'] ?? [];
            $processed = $this->carImageService->handleUploads($images, $meta, 'images', $data['company_id'], $car->id, $slug);

            foreach ($processed as $img) {
                $car->images()->create([
                    'image' => $img['image'],
                    'order' => $img['order'],
                    'is_primary' => $img['is_primary'],
                    'company_id' => $data['company_id'],
                ]);
            }
        }

        // Salvar imagens 360 exterior normais
        if (!empty($data['exterior_360_images'])) {
            $images = $data['exterior_360_images'];
            $meta = $data['exterior_360_meta'] ?? [];
            $processed = $this->car360ExteriorImageService->handleUploads($images, $meta, 'exterior_360_images', $data['company_id'], $car->id, $slug);

            foreach ($processed as $img) {
                $car->car360ExteriorImages()->create([
                    'image' => $img['image'],
                    'order' => $img['order'],
                    'company_id' => $data['company_id'],
                ]);
            }
        }

        return $car;
    }

    public function update(int $id, array $data): mixed
    {
        $car = $this->carRepository->findOrFail($id, 'id');
        $slug = Str::slug("{$data['car_brand_id']}-{$data['car_model_id']}");

        // Atualiza dados principais do carro
        $car->update($data);

        /*
        *|--------------------------------------------------------------------------
        *| Imagens normais
        *|--------------------------------------------------------------------------
        */
        // Só mexe em imagens se o request trouxer algum campo relacionado
        $touchImages =
            array_key_exists('images', $data) ||
            array_key_exists('existing_images', $data) ||
            array_key_exists('existing_images_meta', $data) ||
            array_key_exists('images_meta', $data);

        if ($touchImages) {
            $novas = $data['images'] ?? [];

            // Só apaga se o request trouxe a lista final das existentes
            $hasExistingList = array_key_exists('existing_images', $data);

            if ($hasExistingList) {
                $existentes = $data['existing_images'] ?? [];

                $car->load('images');

                foreach ($car->images as $img) {
                    if (!in_array($img->image, $existentes, true)) {
                        Storage::disk('public')->delete(str_replace('storage/', '', $img->image));
                        $img->delete();
                    }
                }

                // Atualiza meta das existentes só se também veio meta
                if (array_key_exists('existing_images_meta', $data)) {
                    $existingMeta = $data['existing_images_meta'] ?? [];
                    $car->load('images');

                    foreach ($existentes as $idx => $path) {
                        $img = $car->images->firstWhere('image', $path);
                        if (!$img) continue;

                        $metaAtual = $existingMeta[$idx] ?? [];

                        $img->update([
                            'order' => $metaAtual['order'] ?? $img->order,
                            'is_primary' => array_key_exists('is_primary', $metaAtual)
                                ? (bool)$metaAtual['is_primary']
                                : $img->is_primary,
                        ]);
                    }
                }
            }

            // Salva novas imagens (independente de existing_images)
            if (!empty($novas)) {
                $metaNovas = $data['images_meta'] ?? [];

                $processed = $this->carImageService->handleUploads(
                    $novas,
                    $metaNovas,
                    'images',
                    $data['company_id'],
                    $car->id,
                    $slug
                );

                foreach ($processed as $img) {
                    $car->images()->create([
                        'image' => $img['image'],
                        'order' => $img['order'],
                        'is_primary' => $img['is_primary'],
                        'company_id' => $data['company_id'],
                    ]);
                }
            }

            // Garantir 1 primary (boa prática)
            $car->load('images');
            if ($car->images->count() > 0 && !$car->images->contains(fn($i) => (bool)$i->is_primary)) {
                $first = $car->images->sortBy('order')->first();
                if ($first) $first->update(['is_primary' => 1]);
            }
        }

        /*
        *|--------------------------------------------------------------------------
        *| Imagens 360 exterior
        *|--------------------------------------------------------------------------
        */
        if (array_key_exists('exterior_360_images', $data)) {
            $novas = [];
            $existentes = [];

            foreach ($data['exterior_360_images'] as $img) {
                if (is_string($img)) {
                    $existentes[] = $img;
                } else {
                    $novas[] = $img;
                }
            }

            // Apaga as que saíram
            foreach ($car->car360ExteriorImages as $img) {
                if (!in_array($img->image, $existentes)) {
                    Storage::disk('public')->delete(str_replace('storage/', '', $img->image));
                    $img->delete();
                }
            }

            // Adiciona as novas
            $meta = $data['exterior_360_meta'] ?? [];
            $processed = $this->car360ExteriorImageService->handleUploads($novas, $meta, '360_exterior', $data['company_id'], $car->id, $slug);

            foreach ($processed as $img) {
                $car->car360ExteriorImages()->create([
                    'image' => $img['image'],
                    'order' => $img['order'],
                    'company_id' => $data['company_id'],
                ]);
            }

            // Atualiza ordem das que ficaram
            foreach ($car->car360ExteriorImages as $img) {
                $key = array_search($img->image, $existentes);
                $metaAtual = $data['exterior_360_meta'][$key] ?? [];

                $img->update([
                    'order' => $metaAtual['order'] ?? $img->order,
                ]);
            }
        }

        return $car;
    }

    public function destroy(int $id): bool
    {
        $car = $this->repository->findOrFail($id, 'id', ['*'], ['images', 'car360ExteriorImages']);

        if (isset($car['message']) && $car['message'] === "Não há dados com estes parâmetros.") {
            return true;
        }

        $companyId = $car->company_id;
        $slug = Str::slug("{$car->car_brand_id}-{$car->car_model_id}");
        $baseFolder = "company_{$companyId}/cars/{$slug}-{$car->id}";

        // 1) Apagar imagens normais (DB + Storage)
        foreach ($car->images as $img) {
            $relativePath = str_replace('storage/', '', $img->image);
            Storage::disk('public')->delete($relativePath);
            $img->delete();
        }

        // 2) Apagar imagens 360 exterior (DB + Storage)
        foreach ($car->car360ExteriorImages as $img) {
            $relativePath = str_replace('storage/', '', $img->image);
            Storage::disk('public')->delete($relativePath);
            $img->delete();
        }

        // 3) Apagar a pasta inteira do carro (caso tenha sobrado lixo)
        Storage::disk('public')->deleteDirectory($baseFolder);

        $car->delete();

        return true;
    }

    public function generateAiAnalyses(Car $car): mixed
    {
        $mapData = $this->mapDataToOpenAi($car);

        $response = $this->openIaAnalysisCar($mapData);

        return $this->carAiAnalysesService->store([
            'input_data' => json_encode($mapData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'analysis' => $response,
            'status' => 'completed',
            'feedback' => null,
            'car_id' => $car->id,
            'company_id' => $car->company_id,
        ]);
    }

    private function openIaAnalysisCar(array $car)
    {
        $apiKey = config('services.openai.key');

        $systemContent = "Você é um estrategista sênior de marketing automotivo e publicidade digital com foco em performance e conversão. Dado os dados técnicos de um veículo (marca, modelo, versão, ano, combustível, câmbio, quilometragem, cor e demais atributos), você deve analisar e entregar:

        1. **Público-alvo ideal**:
        - Faixa etária predominante
        - Gênero predominante
        - Perfil profissional
        - Estilo de vida

        Indique o género predominante provável, assumindo uma decisão estratégica de comunicação.

        2. **Canal de aquisição ideal entre Google Ads ou Meta (Facebook/Instagram)**:
        - Escolha apenas um canal principal com base no comportamento do público e no tipo de veículo.
        - Justifique claramente o porquê dessa escolha com base em intenção de busca, descoberta, comportamento e contexto de compra.

        3. **Tipo de criativo recomendado**:
        - Formato ideal (ex: reels, carrossel, imagem estática, campanha de busca etc.)
        - Justifique com base no tipo do veículo e no perfil do público-alvo.

        4. **Principais argumentos de venda**:
        - Liste de forma objetiva os diferenciais que devem ser explorados na comunicação.

        🧠 Sua resposta deve ser estratégica, clara e com foco em impacto comercial direto, como se estivesse a orientar um time de mídia e conteúdo digital.

        Não use linguagem genérica nem neutra. Seja claro nas escolhas e assertivo nas recomendações. 
        Responda exclusivamente em JSON válido, sem texto fora do JSON.";

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => $systemContent],
                    ['role' => 'user', 'content' => json_encode($car, JSON_PRETTY_PRINT)],
                ],
                'temperature' => 0.7,
            ]);

        $response->throw();
        $content = $response->json('choices.0.message.content');

        return $content;
    }

    private function mapDataToOpenAi(Car $car): array
    {
        return [
            'Marca' => $car->brand->name,
            'Modelo' => $car->model->name,
            'Versão' => $car->version,
            'Ano' => $car->registration_year,
            'Combustível' => $car->fuel_type,
            'Quilometragem' => $car->mileage_km,
            'Cor' => $car->exterior_color,
            'Câmbio' => $car->transmission,
        ];
    }
}
