<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProposalRequest;
use App\Services\Leads\ProposalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/public/car-proposal?token=… : o "Pedir proposta" do site. O campo invisível
 * "website" preenchido (um robô) responde como se tivesse corrido bem e não grava nada. Limite de
 * pedidos na rota (throttle:public-lead, por IP em hash e por empresa; o IP nunca é guardado).
 */
class CarProposalController extends Controller
{
    public function __construct(private readonly ProposalRequestService $proposals) {}

    public function store(Request $request): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['success' => true, 'message' => 'Pedido recebido.', 'data' => null], 201);
        }
        $form = app(ProposalRequest::class); // valida aqui, depois do campo invisível
        $this->proposals->store($request->input('public_api_company'), $form->validated(), $request);

        return response()->json(['success' => true, 'message' => 'Pedido recebido.', 'data' => null], 201);
    }
}
