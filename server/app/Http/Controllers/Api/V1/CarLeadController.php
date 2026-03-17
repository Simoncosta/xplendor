<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CarLeadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CarLeadController extends Controller
{
    public function __construct(protected CarLeadService $carLeadService) {}

    public function index(Request $request, int $companyId)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $filters = ['company_id' => $companyId];

        if ($request->filled('search')) {
            $filters['name'] = ['like' => $request->input('search')];
        }

        if ($request->filled('status')) {
            $filters['status'] = $request->input('status');
        }

        if ($request->filled('origin')) {
            $filters['origin'] = $request->input('origin');
        }

        // $orderBy = $request->filled('sort_by')
        //     ? [$request->input('sort_by') => $request->input('sort_direction')]
        //     : [];

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $leads = $this->carLeadService->getAll(
            [
                'id',
                'name',
                'email',
                'message',
                'notes',
                'status',
                'created_at',
                'channel',
                'utm_medium',
                'utm_source',
                'car_id'
            ],
            [
                'car:id,status,license_plate,version,car_brand_id,car_model_id',
                'car.brand:id,name',
                'car.model:id,name',
                'car.images:id,image,is_primary,order,car_id'
            ],
            $paginate,
            $filters,
        );

        return ApiResponse::success($leads, 'Leads fetched successfully.');
    }

    public function update(Request $request, int $companyId, int $id)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:new,contacted,qualified,won,lost,spam'],
        ]);

        $lead = $this->carLeadService->update($id, $data);

        return ApiResponse::success($lead, 'Lead updated successfully.');
    }
}
