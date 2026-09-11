<?php

namespace App\Services;

use App\Models\Expense;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use Illuminate\Support\Carbon;

class ExpenseService extends BaseService
{
    public function __construct(protected ExpenseRepositoryInterface $expenseRepository)
    {
        parent::__construct($expenseRepository);
    }

    /**
     * Normaliza a coerência pago/data:
     *  - marcou pago sem data → assume hoje;
     *  - desmarcou pago → limpa a data.
     */
    public function normalizePaidState(array $data): array
    {
        if (array_key_exists('is_paid', $data)) {
            $isPaid = (bool) $data['is_paid'];

            if ($isPaid) {
                $data['paid_at'] = $data['paid_at'] ?? Carbon::now()->toDateString();
            } else {
                $data['paid_at'] = null;
            }
        }

        return $data;
    }

    /**
     * Regra de eliminação da própria despesa (decisão do Simon):
     *  - SEM vínculo (sem fornecedor, sem viatura, sem categoria) → hard delete.
     *  - COM vínculo → NÃO elimina; devolve false para o controller arquivar/avisar
     *    (a despesa é histórico do veículo/fornecedor/categoria).
     */
    public function deleteOrBlock(Expense $expense): bool
    {
        if ($expense->hasLinks()) {
            return false;
        }

        $this->expenseRepository->destroy($expense->id);

        return true;
    }
}
