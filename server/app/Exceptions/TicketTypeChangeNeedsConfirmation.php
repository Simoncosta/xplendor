<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A mudança de tipo anula um orçamento (orçado ou rejeitado): só avança com
 * confirmação explícita. Leva o orçamento atual para o ecrã o mostrar (409).
 */
class TicketTypeChangeNeedsConfirmation extends RuntimeException
{
    public function __construct(
        public readonly string $quoteStatus,
        public readonly ?float $quotedAmount,
        public readonly ?float $estimatedHours,
    ) {
        parent::__construct('Esta mudança anula o orçamento deste pedido. Confirme para continuar.');
    }

    public function payload(): array
    {
        return [
            'confirmation_required' => true,
            'quote_status'          => $this->quoteStatus,
            'quoted_amount'         => $this->quotedAmount,
            'estimated_hours'       => $this->estimatedHours,
        ];
    }
}
