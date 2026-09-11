<?php

namespace App\Services;

use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;

class SupplierService extends BaseService
{
    public function __construct(protected SupplierRepositoryInterface $supplierRepository)
    {
        parent::__construct($supplierRepository);
    }

    /**
     * Regra de eliminação (1c.2b — activada agora que as despesas existem):
     *  - SEM despesas associadas → hard delete.
     *  - COM despesas → NÃO elimina; devolve false para o controller responder
     *    "arquive em vez de eliminar" (422). A UI arquiva (archived=true).
     */
    public function deleteOrBlock(Supplier $supplier): bool
    {
        if ($supplier->expenses()->exists()) {
            return false;
        }

        $this->supplierRepository->destroy($supplier->id);

        return true;
    }
}
