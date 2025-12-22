<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(protected PlanService $planService) {}

    public function index()
    {
        $plans = $this->planService->getAll();

        return ApiResponse::success(PlanResource::collection($plans), 'Plans fetched successfully.');
    }

    public function store(StorePlanRequest $request)
    {
        $plan = $this->planService->store($request->validated());
        return ApiResponse::success(new PlanResource($plan), 'Plan created successfully.');
    }

    public function show(int $id)
    {
        $plan = $this->planService->findOrFail($id, 'id');
        return ApiResponse::success(new PlanResource($plan), 'Plan fetched successfully.');
    }

    public function update(UpdatePlanRequest $request, int $id)
    {
        $plan = $this->planService->update($id, $request->validated());
        return ApiResponse::success(new PlanResource($plan), 'Plan updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->planService->destroy($id);
        return ApiResponse::success(null, 'Plan deleted successfully.');
    }
}
