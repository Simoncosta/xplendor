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
                'phone',
                'message',
                'notes',
                'status',
                'lost_reason',
                'created_at',
                'channel',
                'utm_medium',
                'utm_source',
                'utm_campaign',
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

        // Tenancy 2 camadas: o lead tem de pertencer MESMO a esta empresa
        // (antes atualizava por id sem confirmar a empresa — corrigido).
        $lead = \App\Models\CarLead::where('company_id', $companyId)->find($id);
        if (! $lead) {
            return ApiResponse::error('Lead não encontrada.', 404);
        }

        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', \App\Models\CarLead::STATUSES)],
            // Ao mover para "Perdida", o motivo é OBRIGATÓRIO (não perder sem motivo).
            'lost_reason' => ['nullable', 'required_if:status,lost', 'in:' . implode(',', \App\Models\CarLead::LOSS_REASONS)],
            'notes' => ['nullable', 'string'],
        ]);

        $update = ['status' => $data['status']];

        if ($data['status'] === 'lost') {
            $update['lost_reason'] = $data['lost_reason'];
            $update['closed_at'] = now();
        } elseif ($data['status'] === 'won') {
            $update['lost_reason'] = null; // ganho não tem motivo de perda
            $update['closed_at'] = now();
        } else {
            $update['lost_reason'] = null; // voltou ao funil ativo
            $update['closed_at'] = null;
        }

        if (array_key_exists('notes', $data)) {
            $update['notes'] = $data['notes'];
        }

        $lead->update($update);

        return ApiResponse::success($lead->fresh(), 'Lead updated successfully.');
    }
}
