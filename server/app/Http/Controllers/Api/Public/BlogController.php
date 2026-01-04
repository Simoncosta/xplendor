<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\BlogService;
use App\Services\CompanyService;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function __construct(
        protected CompanyService $companyService,
        protected BlogService $blogService
    ) {}

    public function index(Request $request)
    {
        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $company = $this->companyService->findOrFail(
            $request->input('token'),
            'public_api_token',
            ['id']
        );

        $blogs = $this->blogService->getAll(
            ['*'],
            [],
            $paginate,
            ['company_id' => $company->id]
        );

        return ApiResponse::success($blogs, 'Blogs fetched successfully.');
    }

    public function show(string $slug)
    {
        $blog = $this->blogService->findOrFail(
            $slug,
            'slug',
            ['*'],
            []
        );

        return ApiResponse::success($blog, 'Blog fetched successfully.');
    }
}
