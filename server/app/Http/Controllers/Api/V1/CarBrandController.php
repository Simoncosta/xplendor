<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\CarBrandService;

class CarBrandController extends Controller
{
    public function __construct(protected CarBrandService $carBrandService) {}

    public function index(PaginateRequest $request)
    {
        $data = $request->validate([
            'vehicle_type' => ['nullable', 'in:car,motorcycle,motorhome,caravan'],
        ]);

        $filters = [];

        if ($request->filled('vehicle_type')) {
            $filters['vehicle_type'] = $data['vehicle_type'];
        }

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $columns = !empty($filters)
            ? ['id', 'name', 'slug']
            : ['*'];

        $carBrands = $this->carBrandService->getAll(
            $columns,
            [],
            $paginate,
            $filters,
            ['name' => 'asc'],
        );

        return ApiResponse::success($carBrands, 'Car Brands fetched successfully.');
    }
}
