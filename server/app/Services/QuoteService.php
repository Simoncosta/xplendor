<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\QuoteRepositoryInterface;

/**
 * XPLENDOR — Orçamentos avulsos (gestão comercial, super-admin). Molde fino
 * sobre o BaseService/BaseRepository. Sem lógica de tenancy (é transversal;
 * o acesso é garantido pelo portão EnsureSuperAdmin + defesa em profundidade
 * no controller).
 */
class QuoteService extends BaseService
{
    public function __construct(protected QuoteRepositoryInterface $quoteRepository)
    {
        parent::__construct($quoteRepository);
    }
}
