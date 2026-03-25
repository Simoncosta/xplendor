<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

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
        // Garante que as relations necessárias estão carregadas
        $car->loadMissing(['brand', 'model']);

        return $this->carAiAnalysesService->generate($car);
    }

    public function buildImagesDownloadArchive(int $companyId, int $carId): array
    {
        $car = $this->carRepository->findOrFail(
            $carId,
            'id',
            ['*'],
            ['images', 'externalImages', 'brand:id,name', 'model:id,name']
        );

        if ((int) $car->company_id !== $companyId) {
            throw new \DomainException('Viatura não encontrada para esta empresa.');
        }

        $validImages = collect($car->images ?? [])
            ->filter(function ($image) {
                $relativePath = $this->normalizeImagePath($image->image ?? null);

                return $relativePath !== null && Storage::disk('public')->exists($relativePath);
            })
            ->sortBy([
                ['order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $filename = $this->buildImagesArchiveFilename($car);
        $archivePath = $this->makeTemporaryArchivePath($filename);
        $temporaryFiles = [];

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException('Não foi possível criar o ficheiro ZIP temporário.');
        }

        $addedFiles = 0;

        try {
            if ($validImages->isNotEmpty()) {
                foreach ($validImages as $index => $image) {
                    $relativePath = $this->normalizeImagePath($image->image);
                    if ($relativePath === null) {
                        continue;
                    }

                    $absolutePath = Storage::disk('public')->path($relativePath);
                    $zip->addFile($absolutePath, $this->buildArchiveEntryName($image->image, $index + 1));
                    $addedFiles++;
                }
            } else {
                $externalImages = collect($car->externalImages ?? []);

                foreach ($externalImages as $index => $image) {
                    $temporaryPath = $this->downloadExternalImageTemporarily($image->external_url ?? null, $index + 1);

                    if ($temporaryPath === null) {
                        continue;
                    }

                    $temporaryFiles[] = $temporaryPath;
                    $zip->addFile($temporaryPath, $this->buildArchiveEntryName($image->external_url, $index + 1));
                    $addedFiles++;
                }
            }
        } finally {
            $zip->close();

            foreach ($temporaryFiles as $temporaryFile) {
                if (is_string($temporaryFile) && file_exists($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }

        if ($addedFiles === 0) {
            if (file_exists($archivePath)) {
                @unlink($archivePath);
            }

            throw new \DomainException('Esta viatura não tem imagens disponíveis para download.');
        }

        return [
            'path' => $archivePath,
            'filename' => $filename,
        ];
    }

    private function normalizeImagePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return ltrim(str_replace('storage/', '', $path), '/');
    }

    private function buildImagesArchiveFilename(Car $car): string
    {
        $licensePlate = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) ($car->license_plate ?? '')));
        $base = implode('-', array_filter([
            Str::slug((string) ($car->brand?->name ?? 'carro')),
            Str::slug((string) ($car->model?->name ?? $car->version ?? 'viatura')),
            $licensePlate !== '' ? $licensePlate : (string) $car->id,
        ]));

        return ($base !== '' ? $base : 'viatura-' . $car->id) . '.zip';
    }

    private function makeTemporaryArchivePath(string $filename): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('car-images-', true) . '-' . $filename;
    }

    private function buildArchiveEntryName(string $imagePath, int $position): string
    {
        $basename = pathinfo($imagePath, PATHINFO_FILENAME);
        $extension = pathinfo($imagePath, PATHINFO_EXTENSION);
        $safeName = Str::slug($basename) ?: 'imagem';

        return sprintf('%02d-%s%s', $position, $safeName, $extension ? '.' . strtolower($extension) : '');
    }

    private function downloadExternalImageTemporarily(?string $url, int $position): ?string
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($url);

            if (!$response->successful() || trim($response->body()) === '') {
                Log::warning('[Car Images ZIP] External image download failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $temporaryPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . sprintf('car-external-image-%02d-%s.%s', $position, uniqid(), strtolower($extension));

            file_put_contents($temporaryPath, $response->body());

            return $temporaryPath;
        } catch (\Throwable $exception) {
            Log::warning('[Car Images ZIP] Exception downloading external image', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
