<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyTaskResource;
use App\Models\CompanyTask;
use App\Services\CompanyTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Tarefas internas do cliente (Kanban do stand), LADO DO STAND.
 * PARTILHADAS por company_id: toda a equipa da empresa vê e edita o mesmo
 * quadro. Guard tenant de 2 camadas (padrão do projeto):
 *  1) o utilizador pertence à empresa da rota (ou é root) — authorizeCompanyAccess;
 *  2) a tarefa pertence MESMO a essa empresa — findScoped.
 *
 * Colunas fixas (todo | doing | done). CRUD completo + mover (drag persiste
 * status + posição). O responsável (assignee) é validado no service (tem de ser
 * utilizador da mesma empresa).
 */
class CompanyTaskController extends Controller
{
    public function __construct(protected CompanyTaskService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?CompanyTask
    {
        return CompanyTask::where('company_id', $companyId)->find($id);
    }

    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $tasks = $this->service->listForCompany($companyId);

        return ApiResponse::success(
            CompanyTaskResource::collection($tasks)->resolve(),
            'Tasks fetched successfully.'
        );
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'status'           => ['nullable', 'in:' . implode(',', CompanyTask::STATUSES)],
            'assignee_user_id' => ['nullable', 'integer'],
        ]);

        $task = $this->service->createForCompany($companyId, $data, (int) Auth::id());

        return ApiResponse::success(
            (new CompanyTaskResource($task))->resolve(),
            'Task created successfully.',
            201
        );
    }

    public function update(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $task = $this->findScoped($companyId, $id);
        if (! $task) {
            return ApiResponse::error('Tarefa não encontrada.', 404);
        }

        $data = $request->validate([
            'title'            => ['sometimes', 'required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'assignee_user_id' => ['nullable', 'integer'],
        ]);

        $task = $this->service->updateForCompany($task, $data);

        return ApiResponse::success(
            (new CompanyTaskResource($task))->resolve(),
            'Task updated successfully.'
        );
    }

    /** Move a tarefa para uma coluna/posição (drag). Persiste status + order. */
    public function move(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $task = $this->findScoped($companyId, $id);
        if (! $task) {
            return ApiResponse::error('Tarefa não encontrada.', 404);
        }

        $data = $request->validate([
            'status'      => ['required', 'in:' . implode(',', CompanyTask::STATUSES)],
            'ordered_ids' => ['nullable', 'array'],
            'ordered_ids.*' => ['integer'],
        ]);

        $task = $this->service->move($task, $data['status'], $data['ordered_ids'] ?? []);

        return ApiResponse::success(
            (new CompanyTaskResource($task))->resolve(),
            'Task moved successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $task = $this->findScoped($companyId, $id);
        if (! $task) {
            return ApiResponse::error('Tarefa não encontrada.', 404);
        }

        $task->delete();

        return ApiResponse::success(null, 'Task deleted successfully.');
    }
}
