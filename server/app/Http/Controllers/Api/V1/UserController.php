<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Http\Requests\PaginateRequest;
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

    public function index(PaginateRequest $request, int $companyId)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        // Contas com o acesso retirado não aparecem (ex.: seletor "Vendedor"), salvo pedido explícito.
        $query = \App\Models\User::query()
            ->select(['id', 'name', 'avatar', 'signature', 'email', 'gender', 'birthdate', 'mobile', 'whatsapp', 'email_verified_at', 'role', 'company_id', 'deactivated_at'])
            ->where('company_id', $companyId);
        if (! $request->boolean('include_deactivated')) {
            $query->whereNull('deactivated_at');
        }
        $users = $paginate ? $query->paginate($paginate) : $query->get();

        return ApiResponse::success($users, 'Utilizadores encontrado com sucesso.');
    }

    public function store(StoreUserRequest $request, int $companyId)
    {
        $data = $request->validated();
        $data['role'] = 'user';
        $data['company_id'] = $companyId;
        $invite = $this->userService->store($data);

        return ApiResponse::success($invite, 'Convite enviado com sucesso.');
    }

    public function update(UpdateUserRequest $request, int $companyId, $id)
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

        $token = $this->userService->createToken($user, $user['name']);

        // Equipa XPLENDOR: marca assinada do browser (as aberturas dos orçamentos feitas
        // neste browser não contam). O frontend apaga-a quando o root termina a sessão.
        $extra = $user->role === 'root' ? ['team_marker' => \App\Support\TeamDeviceMarker::issue($user)] : [];

        return ApiResponse::success(
            array_merge(['token' => $token], $user->toArray(), $extra),
            'User created successfully.'
        );
    }

    public function show(int $companyId, int $id)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $user = $this->userService->findOrFail(
            $id,
            'id',
            ['id', 'name', 'avatar', 'signature', 'email', 'role', 'gender', 'birthdate', 'mobile', 'whatsapp', 'company_id'],
            ['company']
        );

        // Verifica se o usuário encontrado pertence à empresa correta
        if ($user['company_id'] !== $companyId) {
            return ApiResponse::error('Utilizador não encontrado ou sem permissão.', 403);
        }

        return ApiResponse::success($user, 'User fetched successfully.');
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

    public function registerByInvite(Request $request)
    {
        $data = $request->validate([
            'token'     => ['required', 'string'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->userService->registerByInvite($data);

        return ApiResponse::success($user, 'Convite aceito com sucesso.');
    }

    public function getUserInviteByToken(string $token)
    {
        $userInvite = $this->userService->getUserInviteByToken($token);

        return ApiResponse::success($userInvite, 'Convite encontrado com sucesso.');
    }
}
