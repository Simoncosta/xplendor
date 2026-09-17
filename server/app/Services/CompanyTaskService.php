<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyTask;
use App\Models\User;
use App\Repositories\Contracts\CompanyTaskRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Tarefas internas do cliente (Kanban do stand). Molde fino sobre o
 * BaseService/BaseRepository. A tenancy (utilizador ↔ empresa da rota, tarefa ↔
 * empresa) é garantida no controller; aqui trata-se a REGRA de negócio:
 *  · o responsável (assignee) tem de ser um utilizador da MESMA empresa;
 *  · criar coloca a tarefa no fim da coluna 'todo' (ou da coluna dada);
 *  · mover persiste status + posição (reordena a coluna de destino).
 */
class CompanyTaskService extends BaseService
{
    public function __construct(protected CompanyTaskRepositoryInterface $companyTaskRepository)
    {
        parent::__construct($companyTaskRepository);
    }

    /** Tarefas da empresa, agrupadas por coluna e ordenadas (order, id). */
    public function listForCompany(int $companyId): Collection
    {
        return CompanyTask::with(['assignee:id,name', 'creator:id,name'])
            ->where('company_id', $companyId)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /** Cria a tarefa na empresa. Valida o responsável; coloca no fim da coluna. */
    public function createForCompany(int $companyId, array $data, int $createdBy): CompanyTask
    {
        $status = $data['status'] ?? 'todo';
        if (! in_array($status, CompanyTask::STATUSES, true)) {
            $status = 'todo';
        }

        $this->assertAssigneeInCompany($companyId, $data['assignee_user_id'] ?? null);

        $data['company_id'] = $companyId;
        $data['created_by'] = $createdBy;
        $data['status'] = $status;
        // Nova tarefa vai para o fim da coluna.
        $data['order'] = (int) CompanyTask::where('company_id', $companyId)
            ->where('status', $status)->max('order') + 1;

        /** @var CompanyTask $task */
        $task = $this->companyTaskRepository->store($data);

        return $task->load(['assignee:id,name', 'creator:id,name']);
    }

    /** Atualiza campos editáveis (título, descrição, responsável). */
    public function updateForCompany(CompanyTask $task, array $data): CompanyTask
    {
        if (array_key_exists('assignee_user_id', $data)) {
            $this->assertAssigneeInCompany($task->company_id, $data['assignee_user_id']);
        }

        $task->update($data);

        return $task->fresh(['assignee:id,name', 'creator:id,name']);
    }

    /**
     * Move a tarefa para uma coluna/posição. Persiste o status e reordena a
     * coluna de destino (compacta os `order` a partir da lista de ids dada).
     */
    public function move(CompanyTask $task, string $status, array $orderedIds): CompanyTask
    {
        if (! in_array($status, CompanyTask::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Estado de tarefa inválido.']]);
        }

        $companyId = $task->company_id;

        $task->update(['status' => $status]);

        // Reordena a coluna de destino segundo a ordem enviada pelo cliente.
        // Só toca em tarefas DESTA empresa e DESTA coluna (defesa de tenancy).
        if (! empty($orderedIds)) {
            $position = 0;
            foreach ($orderedIds as $id) {
                CompanyTask::where('company_id', $companyId)
                    ->where('status', $status)
                    ->where('id', (int) $id)
                    ->update(['order' => $position++]);
            }
        }

        return $task->fresh(['assignee:id,name', 'creator:id,name']);
    }

    /** O responsável (quando indicado) tem de pertencer à empresa da tarefa. */
    private function assertAssigneeInCompany(int $companyId, mixed $assigneeId): void
    {
        if ($assigneeId === null || $assigneeId === '') {
            return;
        }

        $belongs = User::where('id', (int) $assigneeId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'assignee_user_id' => ['O responsável tem de ser um utilizador da empresa.'],
            ]);
        }
    }
}
