<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\CarBrandService;
use Illuminate\Http\Request;

class CarBrandController extends Controller
{
    public function __construct(protected CarBrandService $carBrandService) {}

    public function index(PaginateRequest $request)
    {
        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $carBrands = $this->carBrandService->getAll(
            ['*'],
            [],
            $paginate,
        );

        return ApiResponse::success($carBrands, 'Car Brands fetched successfully.');
    }
}
