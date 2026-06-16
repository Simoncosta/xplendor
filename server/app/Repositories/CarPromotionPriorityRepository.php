<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CarPromotionPriority;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRUD da `car_promotion_priorities` — só acesso a dados, sem business
 * logic. A atomicidade do toggle (única row activa por car) vive no
 * `StockPromotionService`, não aqui.
 */
class CarPromotionPriorityRepository
{
    /**
     * Devolve a marcação ACTIVA da viatura (ou null).
     * Tenant-scoped via `company_id` — defesa em profundidade.
     */
    public function findActiveForCar(int $companyId, int $carId): ?CarPromotionPriority
    {
        return CarPromotionPriority::query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Insere uma nova marcação activa. O caller (Service) é responsável
     * por desactivar quaisquer activas anteriores DENTRO da mesma transaction.
     */
    public function createActive(
        int $companyId,
        int $carId,
        ?int $markedByUserId,
        ?string $note
    ): CarPromotionPriority {
        return CarPromotionPriority::create([
            'company_id'        => $companyId,
            'car_id'            => $carId,
            'marked_by_user_id' => $markedByUserId,
            'marked_at'         => now(),
            'note'              => $note,
            'is_active'         => true,
        ]);
    }

    /**
     * Marca como inactivas TODAS as rows activas de uma viatura específica.
     * Devolve número de rows afectadas.
     *
     * Usado pelo Service em DUAS situações:
     *   - dentro de markForPromotion (limpa estado antes de criar nova)
     *   - dentro de unmarkPromotion (operação principal)
     *
     * Por isso recebe `unmarkedByUserId` opcional — usado em unmark; no flow
     * de markForPromotion fica null porque o "marker novo" é uma nova row.
     */
    public function deactivateAllActiveForCar(
        int $companyId,
        int $carId,
        ?int $unmarkedByUserId
    ): int {
        return CarPromotionPriority::query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->where('is_active', true)
            ->update([
                'is_active'           => false,
                'unmarked_at'         => now(),
                'unmarked_by_user_id' => $unmarkedByUserId,
                'updated_at'          => now(),
            ]);
    }

    /**
     * Query base de marcações activas por empresa — útil para resumos
     * ("N viaturas marcadas como prioridade") sem ter de fazer count manual
     * no Service.
     */
    public function activeForCompanyQuery(int $companyId): Builder
    {
        return CarPromotionPriority::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);
    }
}
