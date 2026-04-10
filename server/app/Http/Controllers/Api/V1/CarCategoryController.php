<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CarCategoryService;
use Illuminate\Http\Request;

class CarCategoryController extends Controller
{
    public function __construct(protected CarCategoryService $carCategoryService) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'vehicle_type' => ['required', 'in:car,motorhome'],
        ]);

        $filters = [
            'vehicle_type' => $data['vehicle_type'],
        ];

        $categories = $this->carCategoryService->getAll(
            ['id', 'name', 'slug'],
            [],
            null,
            $filters,
            ['name' => 'asc'],
        );

        return ApiResponse::success($categories, 'Categories fetched successfully.');
    }
}
