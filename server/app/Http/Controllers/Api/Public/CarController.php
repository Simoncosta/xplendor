<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CarService;
use App\Services\CompanyService;
use Illuminate\Http\Request;

class CarController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
        protected CarService $carService
    ) {}

    public function index(Request $request)
    {
        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $company = $this->companyService->findOrFail(
            $request->input('token'),
            'public_api_token',
            ['id']
        );

        $cars = $this->carService->getAll(
            ['*'],
            ['images', 'car360ExteriorImages', 'brand', 'model'],
            $paginate,
            ['company_id' => $company->id]
        );

        return ApiResponse::success($cars, 'Cars fetched successfully.');
    }
}
