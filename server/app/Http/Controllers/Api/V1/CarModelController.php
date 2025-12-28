<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\CarModelService;
use Illuminate\Http\Request;

class CarModelController extends Controller
{
    public function __construct(protected CarModelService $carModelService) {}

    public function index(PaginateRequest $request)
    {
        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $filter = $request->input('car_brand_id') ? ['car_brand_id' => $request->input('car_brand_id')] : [];

        $carModels = $this->carModelService->getAll(
            ['*'],
            [],
            $paginate,
            $filter
        );

        return ApiResponse::success($carModels, 'Car Models fetched successfully.');
    }
}
