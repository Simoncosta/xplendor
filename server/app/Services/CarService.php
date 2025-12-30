<?php

namespace App\Services;

use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CarService extends BaseService
{
    public function __construct(
        protected CarRepositoryInterface $carRepository,
        // Service
        protected CarImageService $carImageService,
        protected Car360ExteriorImageService $car360ExteriorImageService
    ) {
        parent::__construct($carRepository);
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
        if (array_key_exists('images', $data) || array_key_exists('existing_images', $data)) {
            $novas = $data['images'] ?? [];
            $existentes = $data['existing_images'] ?? [];

            // Apaga tudo que não veio como existente
            foreach ($car->images as $img) {
                if (!in_array($img->image, $existentes)) {
                    Storage::disk('public')->delete(str_replace('storage/', '', $img->image));
                    $img->delete();
                }
            }

            // Salva novas imagens
            $meta = $data['images_meta'] ?? [];
            $processed = $this->carImageService->handleUploads(
                $novas,
                $meta,
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

            // Atualiza meta das existentes
            foreach ($car->images as $img) {
                $key = array_search($img->image, $existentes);
                if ($key === false) continue;

                $metaAtual = $meta[$key] ?? [];

                $img->update([
                    'order' => $metaAtual['order'] ?? $img->order,
                    'is_primary' => $metaAtual['is_primary'] ?? $img->is_primary,
                ]);
            }
        } else {
            // Se não mandou o campo 'images', apaga tudo
            foreach ($car->images as $img) {
                Storage::disk('public')->delete(str_replace('storage/', '', $img->image));
                $img->delete();
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
}
