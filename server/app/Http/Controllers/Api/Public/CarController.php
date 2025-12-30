<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CompanyService;
use Illuminate\Http\Request;

class CarController extends Controller
{
    public function __construct(protected CompanyService $companyService) {}

    public function index(Request $request)
    {
        $cars = $this->companyService->getAll(
            ['id'],
            ['cars'],
            null,
            ['public_api_token' => $request->input('token')]
        );

        return ApiResponse::success($cars, 'Cars fetched successfully.');
    }
}
