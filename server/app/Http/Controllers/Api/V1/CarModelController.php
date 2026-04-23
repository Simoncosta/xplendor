<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\CarModelService;

class CarModelController extends Controller
{
    public function __construct(protected CarModelService $carModelService) {}

    public function index(PaginateRequest $request)
    {
        $data = $request->validate([
            'vehicle_type' => ['nullable', 'in:car,motorcycle,motorhome,caravan'],
            'brand_id' => ['nullable', 'integer', 'exists:car_brands,id'],
        ]);

        $filters = [];

        if ($request->filled('vehicle_type')) {
            $filters['vehicle_type'] = $data['vehicle_type'];
        }

        if ($request->filled('brand_id')) {
            $filters['car_brand_id'] = $data['brand_id'];
        }

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        if ($request->filled('car_brand_id')) {
            $filters['car_brand_id'] = explode(',', $request->input('car_brand_id'));
        }

        $columns = ($request->filled('vehicle_type') || $request->filled('brand_id'))
            ? ['id', 'name']
            : ['*'];

        $carModels = $this->carModelService->getAll(
            $columns,
            [],
            $paginate,
            $filters,
            ['name' => 'asc'],
        );

        return ApiResponse::success($carModels, 'Car Models fetched successfully.');
    }
}
