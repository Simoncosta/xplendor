<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BlogRequest;
use App\Http\Requests\PaginateRequest;
use App\Services\BlogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlogController extends Controller
{
    public function __construct(protected BlogService $blogService) {}

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

        $blogs = $this->blogService->getAll(
            ['*'],
            [],
            $paginate,
            $filter
        );

        return ApiResponse::success($blogs, 'Blogs fetched successfully.');
    }

    public function store(BlogRequest $request, int $companyId)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;
        $data['user_id'] = $user->id;

        if ($data['status'] === 'published') {
            $data['published_at'] = Carbon::now();
        }

        $blog = $this->blogService->store($data);

        return ApiResponse::success($blog, 'Blog created successfully.');
    }

    public function show(int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $blog = $this->blogService->findOrFail(
            $id,
            'id',
            ['*'],
            []
        );

        return ApiResponse::success($blog, 'Blog fetched successfully.');
    }

    public function update(BlogRequest $request, int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $blog = $this->blogService->update($id, $data);

        return ApiResponse::success($blog, 'Blog updated successfully.');
    }

    public function destroy(int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $this->blogService->destroy($id);

        return ApiResponse::success(null, 'Blog deleted successfully.');
    }
}
