<?php

namespace App\Http\Controllers\Api\v1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\DistrictService;
use Illuminate\Http\Request;

class DistrictController extends Controller
{
    public function __construct(protected DistrictService $districtService) {}

    public function index(PaginateRequest $request)
    {
        $paginate = $request->input('perPage') ? ApiPaginate::perPage($request) : null;

        $districts = $this->districtService->getAll(['id', 'name'], [], $paginate);

        return ApiResponse::success($districts, 'Distritos encontrados com sucesso.');
    }

    public function getMunicipalities(PaginateRequest $request, int $id)
    {
        $paginate = $request->input('perPage') ? ApiPaginate::perPage($request) : null;

        $municipalities = $this->districtService->getMunicipalities($id, $paginate);

        return ApiResponse::success($municipalities, 'Municípios encontrados com sucesso.');
    }

    public function getParishes(PaginateRequest $request, int $id)
    {
        $paginate = $request->input('perPage') ? ApiPaginate::perPage($request) : null;

        $parishes = $this->districtService->getParishes($id, $paginate);

        return ApiResponse::success($parishes, 'Freguesias encontradas com sucesso.');
    }
}
