<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\CarIndexRequest;
use App\Http\Resources\Public\CarPublicResource;
use App\Models\VehicleAttribute;
use App\Services\CarBrandService;
use App\Services\CarModelService;
use App\Services\CarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarController extends Controller
{
    /** @var array<string, string> */
    private const BED_LABELS = [
        'camas_gemeas'             => 'Camas gémeas',
        'cama_central'             => 'Cama central',
        'cama_francesa'            => 'Cama francesa',
        'cama_basculante'          => 'Cama basculante',
        'cama_capucino'            => 'Cama capucino',
        'cama_garagem'             => 'Cama de garagem',
        'beliche'                  => 'Beliche',
        'cama_transversal'         => 'Cama transversal',
        'cama_elevatoria_eletrica' => 'Cama elevatória eléctrica',
        'cama_suspensa'            => 'Cama suspensa',
        'cama_convertivel'         => 'Cama convertível',
        'outra'                    => 'Outra',
        'cama_rebativel_cabine'    => 'Rebatível na cabine',
    ];

    public function __construct(
        protected CarService $carService,
        protected CarBrandService $brandService,
        protected CarModelService $modelService,
    ) {}

    public function index(CarIndexRequest $request): JsonResponse
    {
        $company   = $request->input('public_api_company');
        $perPage   = $request->input('perPage') ? ApiPaginate::perPage($request) : null;
        $orderBy   = $request->input('orderBy', 'created_at') ?? 'created_at';
        $orderDir  = $request->input('orderDirection', 'asc');

        $filters = $this->buildIndexFilters($request);

        $cars = $this->carService->getPublicCars($company->id, $filters, $perPage, [$orderBy => $orderDir]);
        $cars = $this->carService->appendPublicSellerContact($cars);

        return ApiResponse::success(CarPublicResource::collection($cars), 'Cars fetched successfully.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->input('public_api_company');

        $car = \App\Models\Car::query()
            ->where('id', $id)
            ->where('company_id', $company->id)
            ->whereIn('status', ['active', 'sold', 'available_soon'])
            ->with(['images', 'externalImages', 'brand', 'model', 'category', 'vehicleAttribute'])
            ->first();

        if (!$car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $car = $this->carService->appendPublicSellerContact($car);

        return ApiResponse::success(new CarPublicResource($car), 'Car fetched successfully.');
    }

    public function filters(Request $request): JsonResponse
    {
        $company = $request->input('public_api_company');
        $cars    = $this->carService->getPublicFiltersData($company->id);

        $habitation = $cars->filter(fn($c) => in_array($c->vehicle_type, ['motorhome', 'caravan'], true));

        $filters = [
            'brands' => $cars->pluck('brand')->filter()->unique('id')
                ->map(fn($b) => ['id' => $b->id, 'name' => $b->name])
                ->values()->toArray(),

            'models' => $cars->pluck('model')->filter()->unique('id')
                ->map(fn($m) => ['id' => $m->id, 'name' => $m->name])
                ->values()->toArray(),

            'categories' => $habitation->filter(fn($c) => $c->vehicle_type === 'motorhome')
                ->pluck('category')->filter()->groupBy('id')
                ->map(fn($group) => [
                    'id'    => $group->first()->id,
                    'name'  => $group->first()->name,
                    'slug'  => $group->first()->slug,
                    'count' => $group->count(),
                ])->values()->toArray(),

            'doors' => $cars->pluck('doors')->filter()->unique()->sort()->values()->toArray(),

            'segments' => $cars->pluck('segment')->filter()->unique()->sort()->values()->toArray(),

            'engine_capacity_cc' => $cars->pluck('engine_capacity_cc')->filter()->unique()->sort()->values()->toArray(),

            'exterior_colors' => $cars->pluck('exterior_color')->filter()->unique()->sort()->values()->toArray(),

            'interior_color' => $cars->pluck('interior_color')->filter()->unique()->sort()->values()->toArray(),

            'mileage_kms' => $cars->pluck('mileage_km')->filter()->unique()->sort()->values()->toArray(),

            'price_gross' => $cars->pluck('price_gross')->filter()->unique()->sort()->values()->toArray(),

            'registration_years' => $cars->pluck('registration_year')->filter()->unique()->sort()->values()->toArray(),

            'seats' => $cars->pluck('seats')->filter()->unique()->sort()->values()->toArray(),

            'fuel_types' => $cars->pluck('fuel_type')->filter()->unique()->sort()->values()->toArray(),

            'transmissions' => $cars->pluck('transmission')->filter()->unique()->sort()->values()->toArray(),

            'bed_types' => $this->buildBedTypesFilter($habitation),

            'seats_range' => $this->buildRange($cars->pluck('seats')->filter()),

            'length_m_range' => $this->buildLengthRange($habitation),

            'available_features' => $this->buildAvailableFeatures($habitation),
        ];

        return ApiResponse::success($filters, 'Filters fetched successfully.');
    }

    /** @return array<string, mixed> */
    private function buildIndexFilters(CarIndexRequest $request): array
    {
        $filters = [];

        if ($request->filled('doors')) {
            $filters['doors'] = $request->integer('doors');
        }

        if ($request->filled('condition')) {
            $filters['condition'] = $request->string('condition')->toString();
        }

        if ($request->filled('min_price_gross') || $request->filled('max_price_gross')) {
            $min = $request->filled('min_price_gross') ? (float) $request->input('min_price_gross') : 0;
            $max = $request->filled('max_price_gross') ? (float) $request->input('max_price_gross') : PHP_INT_MAX;
            $filters['price_gross'] = ['between' => [$min, $max]];
        }

        foreach (['exterior_colors', 'interior_colors', 'registration_years', 'fuel_types', 'transmissions'] as $param) {
            if ($request->filled($param)) {
                $filters[$param] = $request->input($param);
            }
        }

        foreach (['vehicle_type', 'segment', 'category'] as $param) {
            if ($request->filled($param)) {
                $filters[$param] = $request->string($param)->toString();
            }
        }

        foreach (['min_seats', 'max_seats'] as $param) {
            if ($request->filled($param)) {
                $filters[$param] = $request->integer($param);
            }
        }

        foreach (['min_length_m', 'max_length_m'] as $param) {
            if ($request->filled($param)) {
                $filters[$param] = (float) $request->input($param);
            }
        }

        foreach (['has_bathroom', 'has_kitchen', 'has_solar_panel'] as $param) {
            if ($request->filled($param)) {
                $filters[$param] = $request->boolean($param);
            }
        }

        if ($request->filled('bed_types')) {
            $filters['bed_types'] = $request->input('bed_types');
        }

        if ($request->filled('brand')) {
            $brands = $this->brandService->getAll(['id'], [], null, ['name' => $request->string('brand')->toString()]);
            if (!empty($brands[0])) {
                $filters['car_brand_id'] = $brands[0]->id;
            }
        }

        if ($request->filled('model')) {
            $models = $this->modelService->getAll(['id'], [], null, ['name' => $request->string('model')->toString()]);
            if (!empty($models[0])) {
                $filters['car_model_id'] = $models[0]->id;
            }
        }

        return $filters;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildBedTypesFilter(\Illuminate\Support\Collection $habitation): array
    {
        $counts = [];
        foreach ($habitation as $car) {
            $va   = $car->vehicle_attributes;
            $beds = $va['beds'] ?? [];
            foreach ($beds as $bed) {
                $type = $bed['type'] ?? null;
                if ($type) {
                    $counts[$type] = ($counts[$type] ?? 0) + 1;
                }
            }
        }

        return collect($counts)
            ->map(fn($count, $slug) => [
                'slug'  => $slug,
                'label' => self::BED_LABELS[$slug] ?? $slug,
                'count' => $count,
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    /** @param \Illuminate\Support\Collection<int, mixed> $values */
    private function buildRange(\Illuminate\Support\Collection $values): array
    {
        if ($values->isEmpty()) {
            return ['min' => null, 'max' => null];
        }

        return ['min' => $values->min(), 'max' => $values->max()];
    }

    /** @param \Illuminate\Support\Collection<int, mixed> $habitation */
    private function buildLengthRange(\Illuminate\Support\Collection $habitation): array
    {
        $lengths = $habitation
            ->map(fn($c) => ($c->vehicle_attributes['dimensions']['length_m'] ?? null))
            ->filter(fn($v) => $v !== null && $v > 0)
            ->map(fn($v) => (float) $v);

        return $this->buildRange($lengths);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildAvailableFeatures(\Illuminate\Support\Collection $habitation): array
    {
        $featureKeys = ['has_bathroom', 'has_kitchen', 'has_solar_panel'];
        $result      = [];

        foreach ($featureKeys as $key) {
            $count = $habitation->filter(function ($car) use ($key) {
                $va = $car->vehicle_attributes ?? [];
                if ($key === 'has_solar_panel') {
                    return !empty($va['energy_climate'][$key]);
                }
                return !empty($va['habitation_basics'][$key]);
            })->count();

            if ($count > 0) {
                $result[] = ['key' => $key, 'count' => $count];
            }
        }

        return $result;
    }
}
