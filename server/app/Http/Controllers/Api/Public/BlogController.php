<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\BlogPublicResource;
use App\Models\Blog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Blog público dos sites dos clientes: só os artigos PUBLICADOS da empresa do token
 * (o middleware check_company_api_token resolve a empresa) e só os campos públicos.
 */
class BlogController extends Controller
{
    private function query(Request $request): Builder
    {
        $company = $request->input('public_api_company');

        return Blog::query()
            ->where('company_id', $company->id)
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function index(Request $request)
    {
        $query = $this->query($request)->orderByDesc('published_at')->orderByDesc('id');
        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->input('perPage')) {
            $page = $query->paginate(min(100, max(1, (int) $request->input('perPage'))));
            $page->through(fn (Blog $b) => (new BlogPublicResource($b))->resolve());

            return ApiResponse::success($page, 'Blogs fetched successfully.');
        }

        return ApiResponse::success(BlogPublicResource::collection($query->get())->resolve(), 'Blogs fetched successfully.');
    }

    public function show(Request $request, string $slug)
    {
        $blog = $this->query($request)->where('slug', $slug)->first();
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        return ApiResponse::success((new BlogPublicResource($blog))->resolve(), 'Blog fetched successfully.');
    }
}
