<?php

namespace App\Services;

use App\Models\Car;
use App\Repositories\Contracts\CarRepositoryInterface;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CarService extends BaseService
{
    public function __construct(protected CarRepositoryInterface $carRepository)
    {
        parent::__construct($carRepository);
    }

    public function store(array $data): Car
    {
        $car = $this->carRepository->create($data);

        if (request()->hasFile('images')) {
            $this->handleImagesUpload($car, request()->file('images'), request('primary_image_index'));
        }

        return $car->load('images');
    }

    public function update(int $id, array $data): Car
    {
        $car = $this->carRepository->find($id);
        $car->update($data);

        // Se houver novas imagens enviadas
        if (request()->hasFile('images')) {
            // Remove imagens antigas, se for esse o comportamento desejado
            $car->images()->delete();

            // Faz o upload das novas
            $this->handleImagesUpload($car, request()->file('images'), request('primary_image_index'));
        }

        if (request()->hasFile('rotate_exterior_images')) {
            // Remove as antigas
            $car->rotateExteriorImages()->delete();

            // Adiciona as novas
            $this->handleRotateExteriorImagesUpload($car, request()->file('rotate_exterior_images'));
        }

        // todo: Melhorando com exclusão seletiva (opcional)
        // Se o frontend enviar imagens novas e IDs para apagar:
        // if (request()->has('delete_image_ids')) {
        //     $car->images()->whereIn('id', request('delete_image_ids'))->delete();
        // }

        return $car->load(['images', 'rotateExteriorImages']);
    }

    protected function handleImagesUpload(Car $car, array $images, ?int $primaryIndex = null): void
    {
        $company = $car->company ?? $car->company()->first();
        $companySlug = Str::slug($company->name);
        $folder = "companies/{$companySlug}-{$company->id}/cars";

        foreach ($images as $index => $image) {
            $extension = $image->getClientOriginalExtension();

            // Garante um identificador único para cada imagem
            $filename = "{$car->slug}-" . now()->format('YmdHisv') . "-{$index}.{$extension}";

            $path = $image->storeAs($folder, $filename, 'public');

            $car->images()->create([
                'image' => "/storage/{$path}",
                'is_primary' => ($primaryIndex === $index),
            ]);
        }
    }

    public function handleRotateExteriorImagesUpload(Car $car, array $images): void
    {
        $company = $car->company ?? $car->company()->first();
        $companySlug = Str::slug($company->name);
        $folder = "companies/{$companySlug}-{$company->id}/cars_rotate_exterior";

        foreach ($images as $index => $image) {
            $extension = $image->getClientOriginalExtension();

            // Garante um identificador único para cada imagem
            $filename = "{$car->slug}-" . now()->format('YmdHisv') . "-{$index}.{$extension}";

            $path = $image->storeAs($folder, $filename, 'public');

            $car->rotateExteriorImages()->create([
                'order' => $index + 1,
                'image' => "/storage/{$path}",
            ]);
        }
    }
}
