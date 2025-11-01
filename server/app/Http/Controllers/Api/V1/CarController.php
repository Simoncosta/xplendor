<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCarRequest;
use App\Http\Requests\UpdateCarRequest;
use App\Http\Resources\CarResource;
use App\Services\CarService;
use Illuminate\Http\Request;

class CarController extends Controller
{
    public function __construct(protected CarService $carService) {}

    public function index()
    {
        $cars = $this->carService->all();
        return ApiResponse::success(CarResource::collection($cars), 'Cars fetched successfully.');
    }

    public function store(StoreCarRequest $request)
    {
        $car = $this->carService->store($request->validated());
        return ApiResponse::success(new CarResource($car), 'Car created successfully.');
    }

    public function show(int $id)
    {
        $car = $this->carService->findWithRelations($id, ['leads', 'images', 'rotateExteriorImages']);
        if (! $car) {
            return ApiResponse::error('Car not found.');
        }

        return ApiResponse::success(new CarResource($car), 'Car fetched successfully.');
    }

    public function update(UpdateCarRequest $request, int $id)
    {
        $validated = $request->validated();

        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum campo válido foi enviado para atualização.',
            ], 422);
        }

        $car = $this->carService->update($id, $validated);
        return ApiResponse::success(new CarResource($car), 'Car updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->carService->delete($id);
        return ApiResponse::success(null, 'Car deleted successfully.');
    }
}
