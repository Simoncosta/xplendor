<?php

namespace App\Services;

use App\Models\Car;
use App\Models\User;
use App\Repositories\CarPublicRepository;
use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ZipArchive;

class CarService extends BaseService
{
    public function __construct(
        protected CarRepositoryInterface $carRepository,
        protected VehicleAttributeService $vehicleAttributeService,
        // Service
        protected CarImageService $carImageService,
        protected Car360ExteriorImageService $car360ExteriorImageService,
        protected CarAiAnalysesService $carAiAnalysesService,
        protected CarPublicRepository $carPublicRepository,
    ) {
        parent::__construct($carRepository);
    }

    public function getAll(array $columns = ['*'], array $relations = [], ?int $perPage = null, array $filters = [], array $orderBy = []): mixed
    {
        return $this->carRepository->getAllWithAnalytics($columns, $relations, $perPage, $filters, $orderBy);
    }

    public function getPublicCars(int $companyId, array $filters = [], ?int $perPage = null, array $orderBy = []): mixed
    {
        return $this->carPublicRepository->getPublicCars($companyId, $filters, $perPage, $orderBy);
    }

    public function getPublicFiltersData(int $companyId): EloquentCollection
    {
        return $this->carPublicRepository->getPublicFiltersData($companyId);
    }

    public function store(array $data): mixed
    {
        $hasVehicleAttributes = !empty($data['vehicle_attributes']);
        $vehicleAttributes = $this->extractVehicleAttributes($data);
        unset($data['vehicle_attributes']);

        $car = $this->repository->store($data); // Cria carro e retorna ID
        $slug = Str::slug("{$data['car_brand_id']}-{$data['car_model_id']}");

        // Salvar imagens normais
        if (!empty($data['images'])) {
            $images = $data['images'];
            $meta = $data['images_meta'] ?? [];
            $processed = $this->carImageService->handleUploads($images, $meta, 'images', $data['company_id'], $car->id, $slug);

            foreach ($processed as $img) {
                $car->images()->create([
                    'image'         => $img['image'],
                    'original_path' => $img['original_path'] ?? null,
                    'order'         => $img['order'],
                    'is_primary'    => $img['is_primary'],
                    'company_id'    => $data['company_id'],
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

        if ($hasVehicleAttributes) {
            $vehicleAttribute = $this->vehicleAttributeService->setAttributes($car, $vehicleAttributes);

            if ($vehicleAttribute) {
                $car->setRelation('vehicleAttribute', $vehicleAttribute);
            } else {
                $car->setRelation('vehicleAttribute', null);
            }
        }

        return $car->loadMissing('vehicleAttribute');
    }

    public function update(int $id, array $data): mixed
    {
        $shouldSyncVehicleAttributes = array_key_exists('vehicle_attributes', $data);
        $vehicleAttributes = $this->extractVehicleAttributes($data);
        unset($data['vehicle_attributes']);

        $car = $this->carRepository->findOrFail($id, 'id');
        $slug = Str::slug("{$data['car_brand_id']}-{$data['car_model_id']}");

        // Atualiza dados principais do carro
        $car->update($data);

        /*
        *|--------------------------------------------------------------------------
        *| Imagens normais
        *|--------------------------------------------------------------------------
        */
        // Só mexe em imagens se o request trouxer algum campo relacionado.
        // existing_images_present=1 é incluído para cobrir o caso de lista vazia intencional.
        $touchImages =
            array_key_exists('images', $data) ||
            array_key_exists('existing_images', $data) ||
            array_key_exists('existing_images_meta', $data) ||
            array_key_exists('images_meta', $data) ||
            !empty($data['existing_images_present']);

        if ($touchImages) {
            $novas = $data['images'] ?? [];

            // existing_images_present=1 é o sentinel que distingue "lista vazia intencional"
            // de "chave ausente" — FormData não representa arrays vazios, por isso sem o
            // sentinel não podemos saber se o frontend quis apagar tudo ou não quis tocar.
            $hasExistingList =
                array_key_exists('existing_images', $data) ||
                !empty($data['existing_images_present']);

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
                        'image'         => $img['image'],
                        'original_path' => $img['original_path'] ?? null,
                        'order'         => $img['order'],
                        'is_primary'    => $img['is_primary'],
                        'company_id'    => $data['company_id'],
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

        if ($shouldSyncVehicleAttributes) {
            $vehicleAttribute = $this->vehicleAttributeService->setAttributes($car, $vehicleAttributes);

            if ($vehicleAttribute) {
                $car->setRelation('vehicleAttribute', $vehicleAttribute);
            } else {
                $car->setRelation('vehicleAttribute', null);
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

    public function appendPublicSellerContact(mixed $cars): mixed
    {
        if ($cars instanceof AbstractPaginator) {
            $cars->setCollection($this->attachSellerContactToCollection($cars->getCollection()));
            return $cars;
        }

        if ($cars instanceof Collection) {
            return $this->attachSellerContactToCollection($cars);
        }

        if ($cars instanceof Car) {
            return $this->attachSellerContactToCollection(collect([$cars]))->first();
        }

        return $cars;
    }

    public function recropImage(\App\Models\CarImage $image, int $x, int $y, int $width, int $height): void
    {
        $this->carImageService->recrop($image, $x, $y, $width, $height);
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

    private function attachSellerContactToCollection(Collection $cars): Collection
    {
        if ($cars->isEmpty()) {
            return $cars;
        }

        $sellerIds = $cars->pluck('seller_user_id')->filter()->unique()->values();
        $companyIds = $cars->pluck('company_id')->filter()->unique()->values();

        $sellerMap = User::query()
            ->whereIn('id', $sellerIds)
            ->get(['id', 'name', 'avatar', 'mobile', 'whatsapp', 'company_id'])
            ->keyBy('id');

        $adminMap = User::query()
            ->whereIn('company_id', $companyIds)
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id', 'name', 'avatar', 'mobile', 'whatsapp', 'company_id'])
            ->groupBy('company_id')
            ->map(fn(Collection $users) => $users->first());

        return $cars->map(function ($car) use ($sellerMap, $adminMap) {
            $seller = $car->seller_user_id
                ? $sellerMap->get($car->seller_user_id)
                : null;

            if (!$seller) {
                $seller = $adminMap->get($car->company_id);
            }

            $mobile = $seller?->mobile ?: null;
            $phone = null;
            $whatsapp = $mobile ?: null;

            $car->seller_contact = $seller ? [
                'id' => $seller->id,
                'name' => $seller->name,
                'avatar' => $seller->avatar,
                'phone' => $phone,
                'mobile' => $mobile,
                'whatsapp' => $whatsapp,
            ] : null;

            return $car;
        });
    }

    private function extractVehicleAttributes(array $data): array
    {
        $vehicleAttributes = $data['vehicle_attributes'] ?? [];

        if (!is_array($vehicleAttributes)) {
            return [];
        }

        $booleanKeys = [
            'has_bathroom',
            'has_kitchen',
        ];

        $normalized = [];

        foreach ($vehicleAttributes as $key => $value) {
            if (!is_string($key) || $key === '' || str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $arrayValue = $this->normalizeVehicleAttributeArray($key, $value);

                if ($arrayValue !== []) {
                    $normalized[$key] = $arrayValue;
                }

                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                continue;
            }

            if (in_array($key, $booleanKeys, true)) {
                $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_numeric($value)) {
                $normalized[$key] = str_contains((string) $value, '.')
                    ? (float) $value
                    : (int) $value;
                continue;
            }

            $normalized[$key] = $value;
        }

        return Arr::sortRecursive($normalized);
    }

    private function normalizeVehicleAttributeArray(string $key, array $value): array
    {
        if ($key === 'dimensions') {
            $result = [];
            foreach ($value as $k => $v) {
                if (!is_string($k) || $v === null || $v === '') {
                    continue;
                }
                if (is_numeric($v)) {
                    $result[$k] = round((float) $v, 2);
                }
            }
            return $result;
        }

        if ($key === 'weights') {
            $result = [];
            foreach ($value as $k => $v) {
                if (!is_string($k) || $v === null || $v === '') {
                    continue;
                }
                if (is_numeric($v)) {
                    $result[$k] = (int) $v;
                }
            }
            return $result;
        }

        if ($key === 'habitation_basics') {
            $topBoolKeys     = ['has_bathroom', 'has_kitchen', 'has_garage', 'has_awning', 'has_solar_panel'];
            $kitchenBoolKeys = ['has_stove', 'has_oven', 'has_microwave', 'has_extractor', 'has_fridge'];
            $bathroomBoolKeys = ['has_toilet', 'has_shower'];
            $result = [];

            foreach ($value as $k => $v) {
                if (!is_string($k) || $v === null || $v === '') {
                    continue;
                }

                if (in_array($k, $topBoolKeys, true)) {
                    $result[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                    continue;
                }

                if ($k === 'kitchen' && is_array($v)) {
                    $kitchen = [];
                    foreach ($v as $ck => $cv) {
                        if (!is_string($ck) || $cv === null || $cv === '') {
                            continue;
                        }
                        if (in_array($ck, $kitchenBoolKeys, true)) {
                            $kitchen[$ck] = filter_var($cv, FILTER_VALIDATE_BOOLEAN);
                        } elseif (is_numeric($cv)) {
                            $kitchen[$ck] = (int) $cv;
                        } else {
                            $trimmed = is_string($cv) ? trim($cv) : null;
                            if ($trimmed !== null && $trimmed !== '') {
                                $kitchen[$ck] = $trimmed;
                            }
                        }
                    }
                    if (!empty($kitchen)) {
                        $result['kitchen'] = $kitchen;
                    }
                    continue;
                }

                if ($k === 'bathroom' && is_array($v)) {
                    $bathroom = [];
                    foreach ($v as $bk => $bv) {
                        if (!is_string($bk) || $bv === null || $bv === '') {
                            continue;
                        }
                        if (in_array($bk, $bathroomBoolKeys, true)) {
                            $bathroom[$bk] = filter_var($bv, FILTER_VALIDATE_BOOLEAN);
                        } elseif (is_numeric($bv)) {
                            $bathroom[$bk] = (int) $bv;
                        } else {
                            $trimmed = is_string($bv) ? trim($bv) : null;
                            if ($trimmed !== null && $trimmed !== '') {
                                $bathroom[$bk] = $trimmed;
                            }
                        }
                    }
                    if (!empty($bathroom)) {
                        $result['bathroom'] = $bathroom;
                    }
                    continue;
                }

                // other scalar keys
                $trimmed = is_string($v) ? trim($v) : $v;
                if ($trimmed !== '' && $trimmed !== null) {
                    $result[$k] = $trimmed;
                }
            }
            return $result;
        }

        if ($key === 'energy_climate') {
            return $this->normalizeFlatSection($value,
                ['has_solar_panel', 'has_inverter', 'has_gpl', 'has_external_power_socket'],
                ['solar_panel_watts', 'inverter_watts', 'gpl_bottles_count', 'battery_count', 'cabin_battery_count', 'cell_battery_count']
            );
        }

        if ($key === 'exterior') {
            return $this->normalizeFlatSection($value,
                ['has_awning', 'has_national_antenna', 'has_parabolic_antenna', 'has_bike_rack', 'has_motorbike_rack',
                 'has_electric_step', 'has_manual_step', 'has_stabilizers', 'has_spare_wheel', 'has_fix_n_go_kit',
                 'has_bull_eye', 'has_external_wc', 'has_hubcaps'],
                []
            );
        }

        if ($key === 'security') {
            return $this->normalizeFlatSection($value,
                ['has_alarm', 'has_hatch_lock', 'has_cabin_lock', 'has_safe_door', 'has_gas_lock', 'has_entry_door_lock'],
                []
            );
        }

        if ($key === 'chassis_structure') {
            return $this->normalizeFlatSection($value,
                ['has_turbovent_skylight', 'has_panoramic_skylight', 'has_40x40_skylight', 'has_remifront',
                 'has_window_blackouts', 'has_mosquito_nets', 'has_door_mosquito_net', 'has_cabin_blackouts'],
                []
            );
        }

        if ($key === 'interior_furniture') {
            return $this->normalizeFlatSection($value,
                ['has_foldable_table', 'has_rotating_seats', 'has_curtains', 'has_led_lighting', 'has_halo_lighting',
                 'has_tv_support', 'has_tv', 'has_command_panel', 'has_water_infiltrations'],
                []
            );
        }

        if ($key === 'beds') {
            return collect($value)
                ->map(function ($bed) {
                    $type = is_array($bed)
                        ? ($bed['type'] ?? null)
                        : $bed;

                    if (is_string($type)) {
                        $type = trim($type);
                    }

                    return $type ? ['type' => $type] : null;
                })
                ->filter()
                ->values()
                ->all();
        }

        return Arr::sortRecursive(array_filter($value, fn ($item) => $item !== null && $item !== ''));
    }

    /**
     * Normalise a flat key→scalar section (no sub-objects).
     * Bool keys are cast via filter_var; int keys via (int); remainder trimmed as strings.
     *
     * @param array<string,mixed> $value
     * @param list<string>        $boolKeys
     * @param list<string>        $intKeys
     * @return array<string,mixed>
     */
    private function normalizeFlatSection(array $value, array $boolKeys, array $intKeys): array
    {
        $result = [];
        foreach ($value as $k => $v) {
            if (!is_string($k) || $v === null || $v === '') {
                continue;
            }
            if (in_array($k, $boolKeys, true)) {
                $result[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            } elseif (!empty($intKeys) && in_array($k, $intKeys, true)) {
                if (is_numeric($v)) {
                    $result[$k] = (int) $v;
                }
            } else {
                $trimmed = is_string($v) ? trim($v) : null;
                if ($trimmed !== null && $trimmed !== '') {
                    $result[$k] = $trimmed;
                }
            }
        }
        return $result;
    }
}
