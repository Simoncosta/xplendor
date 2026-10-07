<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ManagedCompanyRequest;
use App\Services\Agency\ManagedCompanyRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Pedidos de nova empresa gerida, lado do ROOT: ver, aprovar e recusar com motivo. */
class ManagedCompanyRequestController extends Controller
{
    public function __construct(private readonly ManagedCompanyRequestService $requests) {}

    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    public function index(Request $request)
    {
        $this->ensureRoot();
        $data = $request->validate(['status' => ['nullable', Rule::in(ManagedCompanyRequest::STATUSES)]]);
        $rows = ManagedCompanyRequest::query()->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(200)->get();

        return ApiResponse::success([
            'requests' => $rows->map(fn ($r) => ManagedCompanyRequestService::present($r))->values(),
            'pending_count' => ManagedCompanyRequest::where('status', ManagedCompanyRequest::PENDING)->count(),
        ], 'Pedidos de nova empresa gerida.');
    }

    public function approve(Request $request, int $requestId)
    {
        $this->ensureRoot();

        return ApiResponse::success(ManagedCompanyRequestService::present($this->requests->approve(ManagedCompanyRequest::findOrFail($requestId), $request->user())), 'Pedido aprovado: a empresa foi criada e é gerida pela agência.');
    }

    public function decline(Request $request, int $requestId)
    {
        $this->ensureRoot();
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indique o motivo da recusa.']);

        return ApiResponse::success(ManagedCompanyRequestService::present($this->requests->decline(ManagedCompanyRequest::findOrFail($requestId), $request->user(), $data['reason'])), 'Pedido recusado.');
    }
}
