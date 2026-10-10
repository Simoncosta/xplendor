<?php

namespace App\Services;

use App\Models\Car;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Repositories\Contracts\ExpenseRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExpenseService extends BaseService
{
    public function __construct(protected ExpenseRepositoryInterface $expenseRepository)
    {
        parent::__construct($expenseRepository);
    }

    /** Cria a despesa, depois de confirmar que as ligações são da empresa (company_id nos dados). */
    public function store(array $data): mixed
    {
        $this->assertCompanyReferences((int) ($data['company_id'] ?? 0), $data);

        return parent::store($data);
    }

    /** Atualiza uma despesa da empresa, depois de confirmar que as ligações são da empresa. */
    public function updateForCompany(int $companyId, int $id, array $data): mixed
    {
        $this->assertCompanyReferences($companyId, $data);

        return parent::update($id, $data);
    }

    /**
     * Isolamento (F0): a categoria, o fornecedor e a viatura de uma despesa têm de ser da
     * mesma empresa. O ExpenseRequest já o valida; aqui verifica-se de novo, para qualquer
     * outro caminho que chegue ao serviço.
     */
    public function assertCompanyReferences(int $companyId, array $data): void
    {
        $checks = [
            'expense_category_id' => ExpenseCategory::class,
            'supplier_id' => Supplier::class,
            'car_id' => Car::class,
        ];
        $errors = [];
        foreach ($checks as $field => $model) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== '' && ! $model::query()->whereKey((int) $value)->where('company_id', $companyId)->exists()) {
                $errors[$field] = ['O valor selecionado não pertence a esta empresa.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
