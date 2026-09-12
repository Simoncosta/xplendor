<?php

namespace App\Repositories;

use App\Models\SupportTicket;
use App\Repositories\Contracts\SupportTicketRepositoryInterface;

class SupportTicketRepository extends BaseRepository implements SupportTicketRepositoryInterface
{
    public function __construct(SupportTicket $model)
    {
        parent::__construct($model);
    }
}
