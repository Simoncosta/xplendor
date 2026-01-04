<?php

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\NewsletterService;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function __construct(protected NewsletterService $newsletterService) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255|unique:newsletters',
        ]);
        $data['company_id'] = $request->input('public_api_company')->id;
        $data['subscribed_at'] = date('Y-m-d H:i:s');
        $data['source'] = 'blog';
        $response = $this->newsletterService->store($data);

        return ApiResponse::success($response, 'Newsletter stored successfully.');
    }
}
