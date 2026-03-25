<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CarBrandService;
use App\Services\CarModelService;
use App\Services\CarService;
use App\Services\CompanyService;
use Illuminate\Http\Request;

class CarController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
        protected CarService $carService,
        protected CarBrandService $brandService,
        protected CarModelService $modelService
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

        $filters = ['company_id' => $company->id];

        // Filtros opcionais
        if ($request->filled('doors')) {
            $filters['doors'] = $request->doors;
        }

        if ($request->filled('segment')) {
            $filters['segment'] = $request->segment;
        }

        if ($request->filled('brand')) {
            $brands = $this->brandService->getAll(
                ['id'],
                [],
                null,
                ['name' => $request->brand]
            );
            $filters['car_brand_id'] = $brands[0]->id;
        }

        if ($request->filled('model')) {
            $models = $this->modelService->getAll(
                ['id'],
                [],
                null,
                ['name' => $request->model]
            );
            $filters['car_model_id'] = $models[0]->id;
        }

        $cars = $this->carService->getAll(
            ['*'],
            ['images', 'externalImages', 'car360ExteriorImages', 'brand', 'model', 'seller'],
            $paginate,
            $filters
        );

        $cars = $this->carService->appendPublicSellerContact($cars);

        return ApiResponse::success($cars, 'Cars fetched successfully.');
    }

    public function show(Request $request, int $id)
    {
        $company = $this->companyService->findOrFail(
            $request->input('token'),
            'public_api_token',
            ['id']
        );

        $cars = $this->carService->findOrFail(
            $id,
            'id',
            ['*'],
            ['images', 'externalImages', 'brand', 'model', 'seller']
        );

        if ($cars->company_id !== $company->id) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $cars = $this->carService->appendPublicSellerContact($cars);

        return ApiResponse::success($cars, 'Car fetched successfully.');
    }

    public function filters(Request $request)
    {
        $company = $this->companyService->findOrFail(
            $request->input('token'),
            'public_api_token',
            ['id']
        );

        $cars = $this->carService->getAll(
            ['*'],
            ['images', 'externalImages', 'brand', 'model'],
            null,
            ['company_id' => $company->id]
        );

        $filters['brands'] = collect($cars)
            ->pluck('brand')
            ->filter()
            ->unique('id')
            ->map(fn($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
            ])
            ->values()
            ->toArray();

        $filters['models'] = collect($cars)
            ->pluck('model')
            ->filter()
            ->unique('id')
            ->map(fn($brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
            ])
            ->values()
            ->toArray();

        $filters['doors'] = collect($cars)
            ->pluck('doors')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['segments'] = collect($cars)
            ->pluck('segment')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['engine_capacity_cc'] = collect($cars)
            ->pluck('engine_capacity_cc')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['exterior_colors'] = collect($cars)
            ->pluck('exterior_color')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['mileage_kms'] = collect($cars)
            ->pluck('mileage_km')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['price_gross'] = collect($cars)
            ->pluck('price_gross')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['registration_years'] = collect($cars)
            ->pluck('registration_year')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['seats'] = collect($cars)
            ->pluck('seats')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['fuel_types'] = collect($cars)
            ->pluck('fuel_type')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $filters['transmissions'] = collect($cars)
            ->pluck('transmission')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return ApiResponse::success($filters, 'Filters cars fetched successfully.');
    }
}
