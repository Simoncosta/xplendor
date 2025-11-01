<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(protected UserService $userService) {}

    public function store(StoreUserRequest $request)
    {
        $user = $this->userService->register($request->validated());

        return ApiResponse::success([
            'token' => $this->userService->createToken($user, $user->name),
            'user'  => new UserResource($user),
        ], 'User created successfully.');
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        $user = $this->userService->update($id, $request->validated());

        return ApiResponse::success([
            'user'  => new UserResource($user),
        ], 'User updated successfully.');
    }

    public function login(AuthRequest $request)
    {
        $user = $this->userService->authenticate($request->email, $request->password);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $this->userService->createToken($user, $user->name);
        $userArray = (new UserResource($user))->resolve();

        return ApiResponse::success(
            array_merge(['token' => $token], $userArray),
            'User created successfully.'
        );
    }

    public function me()
    {
        return ApiResponse::success(new UserResource($this->userService->me()), 'User fetched successfully.');
    }

    public function logout(Request $request)
    {
        $this->userService->logout();

        return ApiResponse::success(null, 'Logout successful');
    }

    public function revokeTokens(Request $request)
    {
        $this->userService->revokeToken();

        return ApiResponse::success(null, 'All tokens revoked successfully.');
    }
}
