<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\NewsletterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NewsletterController extends Controller
{
    public function __construct(protected NewsletterService $newsletterService) {}

    public function index(PaginateRequest $request, int $companyId)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $filter = $user->role === 'root' ? [] : ['company_id' => $user->company_id];

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $newsletters = $this->newsletterService->getAll(
            ['*'],
            [],
            $paginate,
            $filter
        );

        return ApiResponse::success($newsletters, 'Newsletters fetched successfully.');
    }
}
