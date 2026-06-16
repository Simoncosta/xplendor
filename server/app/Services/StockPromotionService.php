<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CarPromotionPriority;
use App\Repositories\CarPromotionPriorityRepository;
use App\Repositories\StockPromotionRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra o Relatório A — candidatas a promoção.
 *
 * RESPONSABILIDADE-CHAVE: garantir que cada viatura tem NO MÁXIMO uma
 * marcação activa. MariaDB não suporta partial unique (UNIQUE … WHERE),
 * portanto a regra é enforced em código dentro de uma transaction:
 *   markForPromotion = deactivateAll → create new active   (atómico)
 *   unmarkPromotion  = deactivateAll                       (atómico, idempotente)
 *
 * NÃO toca em orçamento nem Meta Ads — isso é Camada 2 da faixa, vossa,
 * para depois. Esta camada é só "intenção de promover".
 */
class StockPromotionService
{
    public function __construct(
        private readonly StockPromotionRepository $stock,
        private readonly CarPromotionPriorityRepository $priorities,
    ) {
    }

    /** Lista paginada de candidatas com filtros. */
    public function getCandidates(int $companyId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->stock->queryCandidates($companyId, $filters, $perPage);
    }

    /** Totais agregados (sem filtros do utilizador) para o topo do relatório. */
    public function getSummary(int $companyId): array
    {
        return $this->stock->summary($companyId);
    }

    /**
     * Marca a viatura como prioridade de promoção.
     *
     * INVARIANTE: após esta chamada, existe EXACTAMENTE uma row activa para
     * (company_id, car_id). Se já existia uma, fica unmarked (carimbada
     * com `unmarked_at`/`unmarked_by_user_id=null` porque o marker novo é
     * outra entidade — quem fez o re-mark não está a "unmarcar"). O
     * histórico fica preservado para auditoria via `audits` (owen-it).
     *
     * Idempotência: chamar duas vezes com a mesma nota e o mesmo user
     * produz duas rows com is_active=true sequenciais? NÃO — a 2ª chamada
     * desactiva a 1ª (acabou de criar) e insere outra activa. Resultado
     * final: 1 activa. Auditável.
     */
    public function markForPromotion(
        int $companyId,
        int $carId,
        ?int $markedByUserId,
        ?string $note
    ): CarPromotionPriority {
        return DB::transaction(function () use ($companyId, $carId, $markedByUserId, $note) {
            // Passo 1: desactivar TODAS as activas anteriores. unmarked_by=null
            // porque o re-mark é uma transição "automática", não acção humana
            // de desmarcar (o user está a marcar, não a desmarcar).
            $this->priorities->deactivateAllActiveForCar($companyId, $carId, null);

            // Passo 2: criar nova marcação activa.
            return $this->priorities->createActive($companyId, $carId, $markedByUserId, $note);
        });
    }

    /**
     * Desmarca a viatura. Idempotente: chamar com já não-marcada não rebenta,
     * devolve false. Quando há marcação activa, devolve true após desactivar.
     *
     * O `unmarkedByUserId` aqui carimba quem clicou em "desmarcar" — útil
     * para auditoria + UI futura ("desmarcada por X em DD/MM/AAAA").
     */
    public function unmarkPromotion(
        int $companyId,
        int $carId,
        ?int $unmarkedByUserId
    ): bool {
        $affected = DB::transaction(function () use ($companyId, $carId, $unmarkedByUserId) {
            return $this->priorities->deactivateAllActiveForCar($companyId, $carId, $unmarkedByUserId);
        });

        return $affected > 0;
    }
}
