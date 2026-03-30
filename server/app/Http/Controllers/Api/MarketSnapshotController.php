<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketSnapshotRequest;
use App\Services\CarMarketSnapshotService;
use Illuminate\Http\JsonResponse;

class MarketSnapshotController extends Controller
{
    public function __construct(
        protected CarMarketSnapshotService $carMarketSnapshotService
    ) {}

    public function store(StoreMarketSnapshotRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->carMarketSnapshotService->persistSnapshots($validated['snapshots']);

        return ApiResponse::success(null, 'Snapshots processed successfully.');
    }
}
