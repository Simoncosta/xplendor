<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpenseCategoryService extends BaseService
{
    public function __construct(protected ExpenseCategoryRepositoryInterface $expenseCategoryRepository)
    {
        parent::__construct($expenseCategoryRepository);
    }

    /**
     * 14 categorias sugeridas (pt-PT). Importadas a pedido; a empresa arranca vazia.
     */
    public const SUGGESTED = [
        'Pintura',
        'Chaparia',
        'Mecânico',
        'Centro de Inspeções',
        'Pneus e Recauchetagem',
        'Electricista',
        'Importação e Legalização',
        'Chaveiro',
        'Marketing e Publicidade',
        'Transporte',
        'Remunerações Pessoal',
        'Água, Luz e Comunicações',
        'Serviços Jurídicos',
        'Outros Custos',
    ];

    /**
     * Cria as categorias sugeridas em falta para a empresa. Idempotente:
     * não duplica as que já existem (comparação por nome, case-insensitive),
     * portanto serve tanto para o arranque vazio como para "re-importar as que faltam".
     *
     * @return ExpenseCategory[] as categorias efectivamente criadas.
     */
    public function importSuggested(int $companyId): array
    {
        $existing = ExpenseCategory::where('company_id', $companyId)
            ->pluck('name')
            ->map(fn (string $n) => mb_strtolower(trim($n)))
            ->all();

        $created = [];

        foreach (self::SUGGESTED as $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }

            $created[] = ExpenseCategory::create([
                'company_id' => $companyId,
                'name'       => $name,
                'archived'   => false,
            ]);
        }

        return $created;
    }

    /**
     * Nº de despesas que usam esta categoria.
     *
     * 1c.2a: as despesas ainda NÃO existem — devolve 0. O guard `Schema::hasTable`
     * torna isto à prova de futuro: quando a 1c.2b criar a tabela de despesas com
     * `expense_category_id`, a contagem real passa a valer automaticamente e a
     * regra de eliminação abaixo activa-se sem mais alterações.
     * NOTA 1c.2b: confirmar o nome real da tabela ('expenses' vs 'car_expenses').
     */
    public function expensesCount(int $categoryId): int
    {
        foreach (['expenses', 'car_expenses'] as $table) {
            if (Schema::hasTable($table)) {
                return DB::table($table)->where('expense_category_id', $categoryId)->count();
            }
        }

        return 0;
    }

    /**
     * Regra de eliminação (decisão do Simon — nada com histórico desaparece):
     *  - SEM despesas → hard delete.
     *  - COM despesas → NÃO elimina; devolve false para o controller responder
     *    "arquive em vez de eliminar" (422). A UI arquiva (archived=true).
     */
    public function deleteOrBlock(int $categoryId): bool
    {
        if ($this->expensesCount($categoryId) > 0) {
            return false; // bloqueado — tem histórico, deve ser arquivada
        }

        $this->expenseCategoryRepository->destroy($categoryId);

        return true;
    }
}
